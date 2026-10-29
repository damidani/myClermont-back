<?php

declare(strict_types=1);

namespace App\Modules\Account\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Account\Repositories\UserRepository;

/**
 * GET /me/export (spec v1 › RGPD : accès et portabilité) : archive JSON de toutes les données du compte.
 * Les secrets (jetons, empreintes) et les données des autres personnes n'y figurent jamais.
 */
final class ExportController
{
    /** table => [colonnes exportées, colonne du compte] */
    private const TABLES = [
        'favorites'                => ['type, target_id, created_at', 'user_id'],
        'place_reviews'            => ['uid, rating, comment, status, created_at, updated_at', 'user_id'],
        'community_posts'          => ['uid, type, body, status, created_at', 'user_id'],
        'community_comments'       => ['uid, body, status, created_at', 'user_id'],
        'classifieds'              => ['uid, title, description, price_cents, status, published_at, expires_at', 'user_id'],
        'job_applications'         => ['uid, name, email, phone, message, status, created_at', 'user_id'],
        'housing_contact_requests' => ['name, email, phone, message, status, created_at', 'user_id'],
        'service_quote_requests'   => ['name, email, phone, message, status, created_at', 'user_id'],
        'deal_redemptions'         => ['code, redeemed_at, validated_at', 'user_id'],
        'reports'                  => ['target_type, target_id, reason, details, created_at', 'user_id'],
        'subscriptions'            => ['store, product_id, status, expires_at, environment, created_at', 'user_id'],
        'ai_questions'             => ['question, answer, created_at', 'user_id'],
        'point_transactions'       => ['*', 'user_id'],
        'user_badges'              => ['badge_code, earned_at', 'user_id'],
        'devices'                  => ['uid, platform, model, os_version, app_version, locale, topics, last_seen_at, created_at', 'user_id'],
    ];

    public function export(Request $request): Response
    {
        $userId = (int) $request->attributes['user_id'];
        $user = (new UserRepository())->findById($userId);
        $archive = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'account'      => UserRepository::presentSpec($user) + ['email' => $user['email'], 'sign_in' => array_values(array_filter([
                $user['apple_sub'] ? 'apple' : null, $user['google_sub'] ? 'google' : null, $user['email'] ? 'email' : null,
            ]))],
            'events_submitted' => Database::fetchAll('SELECT slug, title, status, starts_at, created_at FROM events WHERE author_user_id = :u', ['u' => $userId]),
        ];
        foreach (self::TABLES as $table => [$columns, $owner]) {
            try {
                $archive[$table] = Database::fetchAll("SELECT {$columns} FROM {$table} WHERE {$owner} = :u", ['u' => $userId]);
            } catch (\PDOException) {
                $archive[$table] = [];
            }
        }

        return Response::json(\App\Core\Contract::camel($archive))
            ->withHeader('Content-Disposition', 'attachment; filename="my-clermont-donnees-' . gmdate('Y-m-d') . '.json"');
    }
}
