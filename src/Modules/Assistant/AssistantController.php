<?php

declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Security\Crypto;
use App\Support\Validator;

/**
 * POST /assistant/answer (spec v1 › Clermont IA) : texte, cartes cliquables, recherches autour de soi et relances,
 * au format AssistantReply de l'app.
 *
 *  1. Mistral AI (API Chat Completions) répond en s'appuyant sur des outils qui lisent la base de My Clermont ;
 *  2. il termine par l'outil « reply » qui cite des références de résultats ; les cartes sont construites par le serveur
 *     à partir de ces résultats : un lien pointe toujours vers un objet qui existe ;
 *  3. quotas : 1 question découverte sans compte (par appareil), puis compte gratuit requis ; 10 par minute dans tous les cas (throttle:ai) ;
 *  4. même question dans la même zone : réponse en cache 10 min ; questions gardées 30 jours sans la position.
 */
final class AssistantController
{
    private const SYSTEM = <<<'TXT'
Tu es Clermont IA, l'assistant de l'application My Clermont pour Clermont-Ferrand et sa métropole.

Règles :
- Réponds en français, en tutoyant, en 120 mots maximum, sur un ton chaleureux et concret.
- N'affirme aucun fait (adresse, prix, horaire, date, disponibilité) qui ne vienne pas d'un outil. Si les outils ne donnent rien, dis-le simplement et propose une alternative.
- Utilise les outils pour toute question sur les sorties, lieux, bons plans, emplois, logements, services, carburants ou la météo.
- Pour un commerce ou service courant autour de l'utilisateur (pharmacie, coiffeur, boulangerie, distributeur, parking…), utilise nearby_search : l'app fera la recherche sur sa carte.
- Sur l'écran carte (screen = map), privilégie les lieux à épingler.
- Santé : en cas d'urgence, donne immédiatement le 15 (SAMU) ou le 112, et le 3237 pour la pharmacie de garde. Ne pose jamais de diagnostic.
- Texte brut uniquement : jamais de Markdown (pas de **gras**, de titres, de listes à puces ni de liens), l'app l'affiche tel quel.
- Honnêteté : n'annonce jamais d'adresses, de lieux ou de bons plans (« voici… », « je te propose… ») sans mettre leurs références dans cards. Si les outils ne renvoient rien, dis-le simplement (« je n'ai pas encore d'adresse référencée près de toi ») et propose une autre piste.
- Termine TOUJOURS par l'outil reply : le texte de ta réponse, les références (champ ref) des résultats à montrer en cartes (6 maximum, uniquement des références reçues des outils), et 2 ou 3 relances courtes que l'utilisateur pourrait taper.
TXT;

    public function answer(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'question'        => 'required|string|min:1|max:500',
            'conversation_id' => 'nullable|uuid',
            'screen'          => 'nullable|in:chat,map',
            'context'         => 'nullable|in:chat,map',
        ]);
        if (!Config::get('assistant.api_key')) {
            throw new HttpException(503, 'not_configured', 'Clermont IA n\'est pas encore disponible.');
        }
        // Position : { latitude, longitude } (brief §8) ou location { lat, lon } ; absente si refusée. Jamais enregistrée.
        $json = $request->json();
        $loc = is_array($json['location'] ?? null) ? $json['location'] : ['lat' => $json['latitude'] ?? null, 'lon' => $json['longitude'] ?? null];
        $lat = is_numeric($loc['lat'] ?? null) ? round((float) $loc['lat'], 3) : 45.7772;
        $lon = is_numeric($loc['lon'] ?? null) ? round((float) $loc['lon'], 3) : 3.0870;
        $screen = $data['context'] ?? $data['screen'] ?? 'chat';
        $conversationId = $data['conversation_id'] ?? Crypto::uuid4();
        $userId = isset($request->attributes['user_id']) ? (int) $request->attributes['user_id'] : null;
        $deviceId = (int) $request->attributes['device']['id'];

        $this->checkQuota($userId, $deviceId);

        $history = Cache::store()->get('ai:conv:' . $conversationId);
        $history = is_array($history) && ($history['owner'] ?? null) === ($userId ?? 'd' . $deviceId) ? $history['turns'] : [];
        $cacheKey = $history === [] ? 'ai:answer:' . sha1(mb_strtolower(trim($data['question'])) . "|{$screen}|" . round($lat, 2) . '|' . round($lon, 2)) : null;
        if ($cacheKey !== null && is_array($cached = Cache::store()->get($cacheKey))) {
            $this->record($userId, $deviceId, $conversationId, $screen, $data['question'], $cached['text'], 'cached', [], 0, 0);
            return Response::success(['conversation_id' => $conversationId] + $cached);
        }

        $tools = new AssistantTools($lat, $lon);
        [$reply, $used, $in, $out, $status] = $this->converse($data['question'], $history, $screen, $lat, $lon, $tools);
        // Un petit modèle renvoie parfois une liste sous forme de texte (« a, b » ou JSON) : on la remet en liste
        foreach (['cards', 'follow_ups'] as $k) {
            if (is_string($reply[$k] ?? null)) {
                $decoded = json_decode($reply[$k], true);
                $reply[$k] = is_array($decoded) ? $decoded : array_map('trim', preg_split('/\s*[|\n;]\s*/u', $reply[$k]) ?: []);
            }
            $reply[$k] = is_array($reply[$k] ?? null) ? array_values($reply[$k]) : [];
        }

        $result = [
            // Filet de sécurité : l'app affiche le texte brut, sans Markdown
            'text'            => trim((string) preg_replace(['/\*\*(.+?)\*\*/u', '/__(.+?)__/u', '/^#+\s*/mu', '/`([^`]*)`/u'], ['$1', '$1', '', '$1'], (string) $reply['text'])),
            // Le petit modèle oublie parfois de citer ses résultats, ou en invente : à défaut, les 3 premiers résultats réels des outils
            'cards'           => CardBuilder::cards($reply['cards'], $tools->seen) ?: CardBuilder::cards(array_slice(array_keys($tools->seen), 0, 3), $tools->seen),
            'nearby_searches' => $tools->nearbySearches,
            'follow_ups'      => array_slice(array_values(array_filter(array_map(static fn ($f) => is_string($f) ? mb_substr(trim($f), 0, 80) : null, $reply['follow_ups'] ?? []))), 0, 3),
        ];
        // Filet de sécurité (bug iOS c3c45faf) : sans carte, retirer les phrases qui annoncent des suggestions à suivre
        if ($result['cards'] === [] && preg_match('/\b(voici|voilà|ci-dessous|ci-après|je te propose|quelques (adresses|bons plans|idées|lieux))\b/iu', $result['text'])) {
            $kept = array_filter(preg_split('/(?<=[.!?])\s+/u', $result['text']) ?: [], static fn ($p) => !preg_match('/\b(voici|voilà|ci-dessous|ci-après|je te propose|quelques (adresses|bons plans|idées|lieux))\b/iu', $p));
            $result['text'] = trim(implode(' ', $kept)) ?: 'Je n\'ai pas encore d\'adresse référencée pour ça près de toi. Essaie une autre question ou regarde la carte de l\'app.';
        }
        if ($status === 'answered') {
            if ($cacheKey !== null) {
                Cache::store()->set($cacheKey, $result, (int) Config::get('assistant.cache_ttl', 600));
            }
            $turns = array_slice(array_merge($history, [['q' => $data['question'], 'a' => $result['text']]]), -4);
            Cache::store()->set('ai:conv:' . $conversationId, ['owner' => $userId ?? 'd' . $deviceId, 'turns' => $turns], 1800);
        }
        $this->record($userId, $deviceId, $conversationId, $screen, $data['question'], $result['text'], $status, $used, $in, $out);

        return Response::success(['conversation_id' => $conversationId] + $result);
    }

    /** @return array{0: array, 1: list<string>, 2: int, 3: int, 4: string} réponse, outils utilisés, jetons, statut */
    private function converse(string $question, array $history, string $screen, float $lat, float $lon, AssistantTools $tools): array
    {
        $definitions = AssistantTools::definitions();
        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        $messages = [['role' => 'system', 'content' => self::SYSTEM]];
        foreach ($history as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['q']];
            $messages[] = ['role' => 'assistant', 'content' => $turn['a']];
        }
        $messages[] = ['role' => 'user', 'content' => sprintf(
            "[%s · écran : %s · position approximative : %.3f, %.3f]\n%s",
            self::frenchDate($paris), $screen, $lat, $lon, $question,
        )];

        $used = [];
        $in = $out = 0;
        // Budget de temps : une requête PHP est coupée à 20 s ; au-delà de 9 s, le modèle doit conclure (outil reply imposé)
        $maxSteps = (int) Config::get('assistant.max_steps', 4);
        $started = microtime(true);
        for ($step = 0; $step <= $maxSteps; $step++) {
            $elapsed = microtime(true) - $started;
            try {
                $response = MistralClient::chat($messages, $definitions, $step === $maxSteps || $elapsed > 9, (int) max(3, min(10, 17 - $elapsed)));
            } catch (\Throwable $e) {
                Logger::warning('Clermont IA : appel en échec', ['error' => mb_substr($e->getMessage(), 0, 300)]);
                throw new HttpException(503, 'upstream_unavailable', 'Clermont IA est momentanément indisponible, réessaie dans un instant.');
            }
            $in += (int) ($response['usage']['prompt_tokens'] ?? 0);
            $out += (int) ($response['usage']['completion_tokens'] ?? 0);
            $message = $response['choices'][0]['message'] ?? [];
            $calls = (array) ($message['tool_calls'] ?? []);
            $text = is_string($message['content'] ?? null) ? $message['content'] : '';

            if ($calls === []) {
                // Réponse en texte sans passer par reply : gardée, sans cartes
                return [['text' => trim($text) ?: 'Je n\'ai pas trouvé de réponse, reformule ta question.', 'cards' => [], 'follow_ups' => []], $used, $in, $out, 'answered'];
            }
            $results = [];
            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = $call['function']['arguments'] ?? [];
                $input = is_array($args) ? $args : (json_decode((string) $args, true) ?: []);
                if ($name === 'reply') {
                    return [$input + ['text' => ''], $used, $in, $out, 'answered'];
                }
                $used[] = $name;
                $results[] = ['role' => 'tool', 'tool_call_id' => (string) ($call['id'] ?? ''), 'name' => $name,
                              'content' => (string) json_encode($tools->run($name, $input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
            $messages[] = ['role' => 'assistant', 'content' => $text, 'tool_calls' => $calls];
            array_push($messages, ...$results);
        }

        return [['text' => 'Je n\'ai pas réussi à finaliser la réponse, réessaie.', 'cards' => [], 'follow_ups' => []], $used, $in, $out, 'failed'];
    }

    /**
     * Décision du 30/10 (brief §8) : un compte gratuit suffit. Sans compte, 1 question découverte par appareil (X-Device-Id),
     * puis 401 account_required. Avec un compte, seul le plafond anti-abus de 10 questions par minute s'applique (throttle:ai).
     */
    private function checkQuota(?int $userId, int $deviceId): void
    {
        if ($userId !== null) {
            return;
        }
        $total = (int) (Database::fetch('SELECT COALESCE(SUM(questions), 0) AS n FROM ai_usage WHERE scope_key = :d', ['d' => 'd:' . $deviceId])['n'] ?? 0);
        if ($total >= (int) Config::get('assistant.free_questions', 1)) {
            throw HttpException::unauthorized('account_required', 'Crée ton compte gratuit pour continuer avec Clermont IA.');
        }
    }

    private function record(?int $userId, int $deviceId, string $conversationId, string $screen, string $question, string $answer, string $status, array $used, int $in, int $out): void
    {
        // Compteur : par compte si connecté, sinon par appareil (question découverte)
        foreach ([$userId !== null ? [$userId, null] : [null, $deviceId]] as [$u, $d]) {
            Database::execute(
                'INSERT INTO ai_usage (day, scope_key, user_id, device_id, questions, tokens_in, tokens_out) VALUES (UTC_DATE(), :k, :u, :d, 1, :i, :o)
                 ON DUPLICATE KEY UPDATE questions = questions + 1, tokens_in = tokens_in + :i2, tokens_out = tokens_out + :o2',
                ['k' => $u !== null ? 'u:' . $u : 'd:' . $d, 'u' => $u, 'd' => $d, 'i' => $in, 'o' => $out, 'i2' => $in, 'o2' => $out],
            );
        }
        Database::execute(
            'INSERT INTO ai_questions (conversation_id, user_id, device_id, screen, question, answer, tools_used, tokens_in, tokens_out, status, created_at)
             VALUES (:c, :u, :d, :s, :q, :a, :t, :i, :o, :st, :now)',
            ['c' => $conversationId, 'u' => $userId, 'd' => $deviceId, 's' => $screen, 'q' => mb_substr($question, 0, 500), 'a' => mb_substr($answer, 0, 2000),
             't' => substr(implode(',', array_unique($used)), 0, 255) ?: null, 'i' => $in, 'o' => $out, 'st' => $status, 'now' => gmdate('Y-m-d H:i:s')],
        );
    }

    private static function frenchDate(\DateTimeImmutable $d): string
    {
        $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $days[(int) $d->format('N') - 1] . ' ' . $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y, H:i');
    }
}
