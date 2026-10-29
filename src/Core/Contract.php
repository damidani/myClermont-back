<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Contrat JSON de la spécification v1 (docs/SPEC_V1.txt › Architecture et conventions), appliqué à toutes les réponses :
 *
 *  - champs en camelCase, identiques aux structures Swift de l'app ;
 *  - pas d'enveloppe : un objet est renvoyé tel quel, une liste devient { "items": [...], "nextCursor": "…" | null } ;
 *  - erreur : { "error": { "code", "message", "details" } } avec les codes fermés de la spec,
 *    le code détaillé interne restant disponible dans details.reason.
 *
 * Les contrôleurs continuent d'écrire Response::success($data, meta: [...]) en snake_case : la conversion est faite ici, une seule fois.
 */
final class Contract
{
    /** Code HTTP → code d'erreur de la spec. */
    private const CODES = [
        400 => 'validation_failed', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
        409 => 'conflict', 413 => 'validation_failed', 415 => 'validation_failed', 422 => 'validation_failed', 429 => 'rate_limited',
        502 => 'upstream_unavailable', 503 => 'upstream_unavailable',
    ];

    /** Codes déjà conformes à la spec, gardés tels quels. */
    private const SPEC_CODES = ['validation_failed', 'unauthenticated', 'forbidden', 'plus_required', 'not_found', 'conflict',
                                'rate_limited', 'upstream_unavailable', 'not_configured', 'server_error'];

    public static function apply(Response $response): Response
    {
        if ($response->payload === null || $response->raw) {
            return $response;
        }
        $p = $response->payload;

        if (($p['success'] ?? true) === false) {
            $response->payload = ['error' => self::error($response->status, $p['error'])];
            return $response;
        }

        $data = $p['data'] ?? null;
        $meta = isset($p['meta']) ? self::camel($p['meta']) : [];
        if ($data === null && $meta === []) {
            $response->status = $response->status === 200 ? 204 : $response->status;
            $response->payload = null;
            return $response;
        }
        if (is_array($data) && array_is_list($data)) {
            $body = ['items' => self::camel($data), 'nextCursor' => $meta['nextCursor'] ?? null];
            unset($meta['nextCursor']);
            $body += $meta;
        } else {
            $body = is_array($data) ? self::camel($data) : ['value' => $data];
            if ($meta !== []) {
                $body['meta'] = $meta; // points / badges gagnés : champ supplémentaire, ignoré par les décodeurs qui ne le connaissent pas
            }
        }
        $response->payload = $body;

        return $response;
    }

    /** Clés snake_case → camelCase, récursivement (les valeurs ne sont jamais modifiées). */
    public static function camel(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[is_string($k) && str_contains($k, '_') ? lcfirst(str_replace('_', '', ucwords($k, '_'))) : $k] = self::camel($v);
        }

        return $out;
    }

    private static function error(int $status, array $e): array
    {
        $internal = (string) ($e['code'] ?? 'server_error');
        $code = in_array($internal, self::SPEC_CODES, true) ? $internal
              : ($internal === 'premium_required' ? 'plus_required' : (self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'validation_failed')));
        $details = $e['details'] ?? null;

        if ($code === 'validation_failed' && is_array($details) && $details !== [] && !array_is_list($details) && !isset($details['field'])) {
            // { "title": "message" } → { "field": "title", "fields": { … } } : l'app met en évidence le premier champ fautif
            $fields = self::camel($details);
            $details = ['field' => (string) array_key_first($fields), 'fields' => $fields];
        } elseif (is_array($details)) {
            $details = self::camel($details);
        }
        if ($internal !== $code) {
            $details = (is_array($details) ? $details : ($details === null ? [] : ['value' => $details])) + ['reason' => $internal];
        }

        return ['code' => $code, 'message' => (string) ($e['message'] ?? ''), 'details' => $details ?: null];
    }
}
