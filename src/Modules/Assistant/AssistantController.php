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
- Pour un commerce ou service courant autour de l'utilisateur (pharmacie, coiffeur, boulangerie, distributeur, parking…), utilise nearby_search : il renvoie les adresses réelles les plus proches (OpenStreetMap) à citer dans cards.
- Le « Contexte de la conversation » décrit les échanges précédents et les derniers résultats affichés : « il », « elle », « ses », « y », « le premier » renvoient au n° 1, « le deuxième » au n° 2. Ne relance pas une recherche « le plus proche » pour une question de suite. N'invente jamais d'horaires absents.
- Sur l'écran carte (screen = map), privilégie les lieux à épingler.
- Santé : en cas d'urgence, donne immédiatement le 15 (SAMU) ou le 112, et le 3237 pour la pharmacie de garde. Ne pose jamais de diagnostic.
- Texte brut uniquement : jamais de Markdown (pas de **gras**, de titres, de listes à puces ni de liens), l'app l'affiche tel quel.
- Honnêteté : n'annonce jamais d'adresses, de lieux ou de bons plans (« voici… », « je te propose… », « je vais te montrer… », « sur la carte ») sans mettre leurs références dans cards. Si les outils ne renvoient rien, dis-le simplement (« je n'ai pas encore d'adresse référencée près de toi ») et propose une autre piste.
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
        // Contexte envoyé par l'app à partir de la 2e question (ce801f2a) : facultatif, tronqué sans erreur
        $conv = self::conversation($json['conversation'] ?? null);
        $conversationId = $data['conversation_id'] ?? $conv['id'] ?? Crypto::uuid4();
        $userId = isset($request->attributes['user_id']) ? (int) $request->attributes['user_id'] : null;
        $deviceId = (int) $request->attributes['device']['id'];

        $this->checkQuota($userId, $deviceId);

        $memory = Cache::store()->get('ai:conv:' . $conversationId);
        $memory = is_array($memory) && ($memory['owner'] ?? null) === ($userId ?? 'd' . $deviceId) ? $memory : ['turns' => [], 'results' => []];
        // L'historique de l'app fait foi ; la mémoire du serveur (2 h) sert quand l'app n'en envoie pas
        $history = $conv['history'] !== [] ? $conv['history'] : $memory['turns'];
        $lastResults = $conv['last_results'] !== [] ? $conv['last_results'] : ($memory['results'] ?? []);
        $cacheKey = $history === [] && $lastResults === [] ? 'ai:answer:' . sha1(mb_strtolower(trim($data['question'])) . "|{$screen}|" . round($lat, 2) . '|' . round($lon, 2)) : null;
        if ($cacheKey !== null && is_array($cached = Cache::store()->get($cacheKey))) {
            $this->record($userId, $deviceId, $conversationId, $screen, $data['question'], $cached['text'], 'cached', [], 0, 0);
            return Response::success(['conversation_id' => $conversationId] + $cached);
        }

        $located = is_numeric($loc['lat'] ?? null) && is_numeric($loc['lon'] ?? null);
        // Question de suite sur un lieu déjà montré (« ses horaires », « le deuxième ») : jamais une nouvelle recherche
        if ($lastResults !== [] && ($follow = Nearby::followUp($data['question'])) !== null) {
            [$intent, $index] = $follow;
            $place = $index === -1 ? end($lastResults) : ($lastResults[$index] ?? $lastResults[0]);
            $d = Nearby::detail($place, $intent, $located ? $lat : Nearby::JAUDE[0], $located ? $lon : Nearby::JAUDE[1], $located);
            $result = ['text' => $d['text'], 'cards' => $d['cards'], 'nearby_searches' => [], 'follow_ups' => $d['follow_ups']];
            $this->remember($conversationId, $userId, $deviceId, $history, $data['question'], $d['text'], $lastResults);
            $this->record($userId, $deviceId, $conversationId, $screen, $data['question'], $result['text'], 'answered', ['followup:' . $intent], 0, 0);

            return Response::success(['conversation_id' => $conversationId] + $result);
        }

        // Commerce ou service « le plus proche » (iOS 54d34637, afe694b8) : réponse factuelle depuis OpenStreetMap et la base, sans le modèle
        $kind = Nearby::detect($data['question']);
        if ($kind !== null) {
            $near = Nearby::reply($kind, $located ? $lat : Nearby::JAUDE[0], $located ? $lon : Nearby::JAUDE[1], $located, $screen, $data['question']);
            $result = ['text' => $near['text'], 'cards' => $near['cards'], 'nearby_searches' => [], 'follow_ups' => $near['follow_ups']];
            $this->remember($conversationId, $userId, $deviceId, $history, $data['question'], $near['text'], self::results($near['results']) ?: $lastResults);
            $this->record($userId, $deviceId, $conversationId, $screen, $data['question'], $result['text'], 'answered', ['nearby:' . $kind], 0, 0);

            return Response::success(['conversation_id' => $conversationId] + $result);
        }

        $tools = new AssistantTools($lat, $lon);
        [$reply, $used, $in, $out, $status] = $this->converse($data['question'], $history, $screen, $lat, $lon, $tools, $conv['summary'], $lastResults);
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
        // Filet de sécurité (bugs iOS c3c45faf, 54d34637) : sans carte, retirer les phrases qui annoncent des suggestions ou un affichage
        $promise = '/\\b(voici|voilà|ci-dessous|ci-après|je te propose|je vais te montrer|je te montre|je t\'affiche|l\'app (va )?te montre|sur la carte|quelques (adresses|bons plans|idées|lieux))\\b/iu';
        if ($result['cards'] === [] && preg_match($promise, $result['text'])) {
            $kept = array_filter(preg_split('/(?<=[.!?])\\s+/u', $result['text']) ?: [], static fn ($p) => !preg_match($promise, $p));
            $result['text'] = trim(implode(' ', $kept)) ?: 'Je n\'ai pas encore d\'adresse référencée pour ça près de toi. Essaie une autre question ou regarde la carte de l\'app.';
        }
        if ($status === 'answered') {
            if ($cacheKey !== null) {
                Cache::store()->set($cacheKey, $result, (int) Config::get('assistant.cache_ttl', 600));
            }
            $shown = self::results(array_values(array_filter($tools->seen, static fn ($r) => $r['type'] === 'nearby')));
            $this->remember($conversationId, $userId, $deviceId, $history, $data['question'], $result['text'], $shown ?: $lastResults);
        }
        $this->record($userId, $deviceId, $conversationId, $screen, $data['question'], $result['text'], $status, $used, $in, $out);

        return Response::success(['conversation_id' => $conversationId] + $result);
    }

    /** @return array{0: array, 1: list<string>, 2: int, 3: int, 4: string} réponse, outils utilisés, jetons, statut */
    private function converse(string $question, array $history, string $screen, float $lat, float $lon, AssistantTools $tools, string $summary = '', array $lastResults = []): array
    {
        $definitions = AssistantTools::definitions();
        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        $messages = [['role' => 'system', 'content' => self::SYSTEM]];
        // Contexte de la conversation (ce801f2a) : synthèse de l'app et derniers résultats affichés, numérotés
        $context = trim($summary);
        if ($lastResults !== []) {
            $context .= "\nDerniers résultats affichés (« il », « ses », « le premier » = n° 1) :\n" . implode("\n", array_map(
                static fn ($r, $i) => ($i + 1) . '. ' . $r['name'] . ($r['detail'] !== '' ? ' — ' . $r['detail'] : '') . ($r['phone'] ? ' — tél. ' . $r['phone'] : ''),
                $lastResults, array_keys($lastResults)));
        }
        if ($context !== '') {
            $messages[] = ['role' => 'system', 'content' => "Contexte de la conversation :\n" . mb_substr($context, 0, 2400)];
        }
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

    /** Mémoire de conversation : 4 derniers échanges et derniers lieux montrés, 2 h au plus (ce801f2a), jamais dans les journaux */
    private function remember(string $conversationId, ?int $userId, int $deviceId, array $history, string $question, string $answer, array $results = []): void
    {
        $turns = array_slice(array_merge($history, [['q' => $question, 'a' => $answer]]), -4);
        Cache::store()->set('ai:conv:' . $conversationId, ['owner' => $userId ?? 'd' . $deviceId, 'turns' => $turns, 'results' => array_slice($results, 0, 5)], 7200);
    }

    /** Lieux trouvés → format lastResults de l'app */
    private static function results(array $found): array
    {
        return array_map(static fn ($r) => [
            'type' => 'place', 'id' => (string) $r['spot']['id'], 'name' => (string) $r['spot']['name'], 'detail' => (string) ($r['address'] ?? $r['spot']['subtitle'] ?? ''),
            'category' => (string) $r['spot']['category'], 'latitude' => (float) $r['spot']['latitude'], 'longitude' => (float) $r['spot']['longitude'],
            'phone' => $r['spot']['phone'] ?? null, 'website' => $r['spot']['website'] ?? null,
        ], array_slice($found, 0, 5));
    }

    /**
     * Champ conversation de l'app : { id, turn, summary (1 600 car.), history (8 messages de 600 car.), lastResults (5) }.
     * Tout ce qui est mal formé est ignoré : jamais d'erreur.
     *
     * @return array{id: ?string, summary: string, history: list<array{q: string, a: string}>, last_results: list<array>}
     */
    private static function conversation(mixed $c): array
    {
        $out = ['id' => null, 'summary' => '', 'history' => [], 'last_results' => []];
        if (!is_array($c)) {
            return $out;
        }
        if (is_string($c['id'] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $c['id'])) {
            $out['id'] = strtolower($c['id']);
        }
        $out['summary'] = is_string($c['summary'] ?? null) ? mb_substr($c['summary'], 0, 1600) : '';
        $pending = null;
        foreach (array_slice(is_array($c['history'] ?? null) ? $c['history'] : [], -8) as $m) {
            if (!is_array($m) || !is_string($m['text'] ?? null)) {
                continue;
            }
            $text = mb_substr($m['text'], 0, 600);
            if (($m['role'] ?? '') === 'user') {
                $pending = $text;
            } elseif (($m['role'] ?? '') === 'assistant' && $pending !== null) {
                $out['history'][] = ['q' => $pending, 'a' => $text];
                $pending = null;
            }
        }
        foreach (array_slice(is_array($c['last_results'] ?? $c['lastResults'] ?? null) ? ($c['last_results'] ?? $c['lastResults']) : [], 0, 5) as $r) {
            if (!is_array($r) || !is_string($r['name'] ?? null) || !is_numeric($r['latitude'] ?? null) || !is_numeric($r['longitude'] ?? null)) {
                continue; // événement, ville… : sans coordonnées, pas de question de suite sur un lieu
            }
            $out['last_results'][] = [
                'type' => is_string($r['type'] ?? null) ? $r['type'] : 'place', 'id' => mb_substr((string) ($r['id'] ?? $r['name']), 0, 120), 'name' => mb_substr($r['name'], 0, 160),
                'detail' => is_string($r['detail'] ?? null) ? mb_substr($r['detail'], 0, 255) : '', 'category' => is_string($r['category'] ?? null) ? $r['category'] : 'lieu',
                'latitude' => (float) $r['latitude'], 'longitude' => (float) $r['longitude'],
                'phone' => is_string($r['phone'] ?? null) ? mb_substr($r['phone'], 0, 40) : null, 'website' => is_string($r['website'] ?? null) ? mb_substr($r['website'], 0, 255) : null,
            ];
        }

        return $out;
    }

    /**
     * Décision du 30/10 (brief §8) : un compte gratuit suffit. Sans compte, 1 question découverte par appareil (X-Device-Id),
     * puis 401 account_required. Avec un compte : 5 questions par jour de Paris, 300 pour un membre My Clermont+ (9cdabedf), et 10 par minute (throttle:ai).
     */
    private function checkQuota(?int $userId, int $deviceId): void
    {
        if ($userId !== null) {
            // My Clermont+ (9cdabedf) : 5 questions par jour de Paris (Chat et carte cumulés) pour un compte gratuit, 300 pour un membre
            $tz = new \DateTimeZone('Europe/Paris');
            $midnight = new \DateTimeImmutable('today', $tz);
            $used = (int) Database::fetch('SELECT COUNT(*) AS n FROM ai_questions WHERE user_id = :u AND created_at >= :d',
                ['u' => $userId, 'd' => $midnight->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')])['n'];
            $free = (int) Config::get('assistant.daily_free', 5);
            if ($used < $free) {
                return;
            }
            $member = \App\Modules\Subscriptions\SubscriptionsModule::isMember($userId, true);
            $limit = $member ? (int) Config::get('assistant.daily_member', 300) : $free;
            if ($used >= $limit) {
                throw new HttpException(429, 'quota_exceeded', $member
                    ? 'Tu as atteint la limite de questions pour aujourd\'hui. Reviens demain !'
                    : "Tu as utilisé tes {$free} questions gratuites d'aujourd'hui.",
                    ['limit' => $limit, 'reset_at' => $midnight->modify('+1 day')->format('Y-m-d\TH:i:sP')]);
            }
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
