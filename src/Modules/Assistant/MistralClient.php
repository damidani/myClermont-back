<?php

declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Cache\Cache;
use App\Content\Import\HttpClient;
use App\Core\Config;

/**
 * Moteur de Clermont IA : API Chat Completions de Mistral AI (https://api.mistral.ai/v1/chat/completions), appel d'outils.
 * La clé (MISTRAL_API_KEY) reste sur le serveur, jamais dans l'app.
 */
final class MistralClient
{
    private const URL = 'https://api.mistral.ai/v1/chat/completions';

    /**
     * @param list<array> $messages messages au format Mistral (system, user, assistant, tool)
     * @param list<array> $tools    définitions AssistantTools::definitions()
     * @return array réponse décodée (choices[0].message, usage)
     */
    public static function chat(array $messages, array $tools, bool $forceReply = false, int $timeout = 12): array
    {
        // Modèle principal, puis modèles de repli si Mistral répond 429 (limite du forfait ou capacité).
        // Un modèle refusé est sauté pendant 10 min : pas d'aller-retour inutile à chaque question.
        $models = array_values(array_unique(array_filter([(string) Config::get('assistant.model', 'mistral-medium-latest'), ...(array) Config::get('assistant.fallback_models', [])])));
        $available = array_values(array_filter($models, static fn ($m) => !Cache::store()->get('mistral:limited:' . $m))) ?: [end($models)];
        foreach ($available as $i => $model) {
            try {
                return self::call($model, $messages, $tools, $forceReply, $timeout);
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'HTTP 429') || $i === count($available) - 1) {
                    throw $e;
                }
                Cache::store()->set('mistral:limited:' . $model, true, 600);
            }
        }
        throw new \RuntimeException('Aucun modèle Mistral configuré.');
    }

    private static function call(string $model, array $messages, array $tools, bool $forceReply, int $timeout): array
    {
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'tools'       => array_map(static fn (array $t) => ['type' => 'function', 'function' => [
                'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['inputSchema'],
            ]], $tools),
            // L'outil reply termine toujours la réponse : un outil à chaque tour, et reply imposé au dernier
            'tool_choice' => $forceReply ? ['type' => 'function', 'function' => ['name' => 'reply']] : 'any',
            'max_tokens'  => (int) Config::get('assistant.max_tokens', 600),
            'temperature' => 0.3,
        ];
        $r = (new HttpClient(timeout: max(3, $timeout), retries: 0))->request('POST', self::URL, [
            'Authorization: Bearer ' . Config::get('assistant.api_key'),
            'Content-Type: application/json',
            'Accept: application/json',
        ], (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Mistral HTTP ' . $r['status'] . ' : ' . mb_substr((string) $r['body'], 0, 300));
        }

        return json_decode((string) $r['body'], true, 64, JSON_THROW_ON_ERROR);
    }
}
