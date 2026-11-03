<?php

declare(strict_types=1);

namespace App\Modules\Pro;

/** Formats JSON de l'espace pro (clés snake_case : le contrat les passe en camelCase). */
final class ProPresenter
{
    public static function merchant(array $m): array
    {
        return [
            'id' => $m['uid'], 'first_name' => $m['first_name'], 'last_name' => $m['last_name'], 'email' => $m['email'],
            'phone' => $m['phone'], 'job_title' => $m['job_title'], 'created_at' => Pro::iso($m['created_at']),
            'two_factor' => !empty($m['totp_secret']), 'notifications' => Pro::prefs($m['notifications']),
        ];
    }

    public static function business(array $b): array
    {
        return [
            'id' => $b['uid'], 'legal_name' => $b['legal_name'], 'siret' => $b['siret'], 'naf_code' => $b['naf_code'], 'naf_label' => $b['naf_label'],
            'activity' => $b['activity'], 'modules' => self::list($b['modules']), 'goals' => self::list($b['goals']),
            'plan' => $b['plan'], 'plan_since' => Pro::iso($b['plan_since']), 'verification' => $b['verification'], 'created_at' => Pro::iso($b['created_at']),
            // VerificationReview (ca817cb4), sans les signaux réservés à l'équipe
            'review' => ['submitted_at' => Pro::iso($b['verification_submitted_at'] ?? null), 'decided_at' => Pro::iso($b['verification_decided_at'] ?? null)]
                + array_intersect_key(Verification::review($b), array_flip(['sirene', 'claim', 'request', 'refusal', 'history'])),
        ];
    }

    public static function member(array $m): array
    {
        return [
            'id' => $m['uid'], 'name' => trim($m['first_name'] . ' ' . $m['last_name']) ?: $m['email'], 'email' => $m['email'],
            'role' => $m['role'], 'status' => $m['status'], 'invited_at' => Pro::iso($m['invited_at'] ?? $m['created_at']),
        ];
    }

    public static function limits(array $business): array
    {
        $l = Pro::limits($business);

        return ['active_deals' => $l['active_deals'], 'team' => $l['team'], 'photos' => $l['photos'], 'stats_days' => $l['stats_days'],
                'csv_export' => $l['csv_export'], 'flash' => $l['flash']];
    }

    public static function notification(array $n): array
    {
        return ['id' => $n['uid'], 'kind' => $n['kind'], 'title' => $n['title'], 'body' => $n['body'], 'href' => $n['href'],
                'created_at' => Pro::iso($n['created_at']), 'read' => $n['read_at'] !== null];
    }

    /** @return list<string> */
    public static function list(?string $csv): array
    {
        return array_values(array_filter(explode(',', (string) $csv), 'strlen'));
    }
}
