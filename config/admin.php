<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Back-office web (/admin).
 *
 * Les formulaires sont générés à partir du schéma MySQL (types, ENUM, clés étrangères, NULL) :
 * une nouvelle colonne apparaît toute seule. Ce fichier ne décrit que ce que le schéma ne dit pas :
 * libellés, colonnes de liste, champs cachés / en lecture seule, et qui a le droit de quoi.
 *
 * Rôles : admin (tout), editor (contenus), moderator (utilisateurs, communauté, signalements).
 * "roles" = rôles autorisés EN PLUS d'admin. "readonly" => true = consultation seule.
 * "where" = filtre SQL fixe (écrit ici, jamais issu d'une saisie) limitant les lignes visibles.
 */

$editor = ['editor'];
$moderator = ['moderator'];
$both = ['editor', 'moderator'];

return [
    'enabled'          => (bool) Env::get('ADMIN_ENABLED', true),
    // Liste d'IP autorisées (vide = toutes). Ex. : ADMIN_ALLOWED_IPS="1.2.3.4,5.6.7.8"
    'allowed_ips'      => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('ADMIN_ALLOWED_IPS', ''))))),
    'session_ttl'      => (int) Env::get('ADMIN_SESSION_TTL', 8 * 3600),   // durée maximale d'une session
    'idle_timeout'     => (int) Env::get('ADMIN_IDLE_TIMEOUT', 3600),      // déconnexion après inactivité
    'require_2fa'      => (bool) Env::get('ADMIN_REQUIRE_2FA', false),     // impose la double authentification à tous
    'login_attempts'   => 5,                                              // essais par 15 min (par IP et par email)
    'per_page'         => 30,
    'timezone'         => 'Europe/Paris',                                 // dates saisies / affichées (stockées en UTC)
    'upload_dir'       => 'admin',                                        // sous public/media/
    'max_image_size'   => 8 * 1024 * 1024,

    // Colonnes jamais affichées ni modifiables (secrets, données techniques)
    'hidden_columns'   => ['password_hash', 'token_hash', 'secret_encrypted', 'totp_secret', 'content_hash', 'pair_key', 'storage_path', 'csrf_token', 'id_hash'],
    // Colonnes gérées automatiquement
    'auto_columns'     => ['created_at', 'updated_at'],

    'groups' => [
        'Utilisateurs' => [
            'users' => ['label' => 'Utilisateurs', 'roles' => $moderator, 'list' => ['id', 'username', 'email', 'points', 'premium_until', 'status', 'created_at'],
                        'search' => ['username', 'email', 'uid'], 'password' => 'password_hash', 'readonly_columns' => ['referral_code']],
            'user_badges'        => ['label' => 'Badges obtenus', 'roles' => $moderator, 'search' => ['badge_code']],
            'point_transactions' => ['label' => 'Historique des points', 'roles' => $moderator, 'readonly' => true],
            'devices'            => ['label' => 'Appareils', 'roles' => $moderator, 'list' => ['id', 'uid', 'platform', 'model', 'app_version', 'user_id', 'status', 'last_seen_at'],
                                     'search' => ['uid', 'model'], 'can_create' => false],
            'neighborhoods'      => ['label' => 'Quartiers', 'roles' => $editor],
            'user_blocks'        => ['label' => 'Blocages entre utilisateurs', 'roles' => $moderator],
        ],
        'Agenda' => [
            'events'               => ['label' => 'Événements', 'roles' => $editor, 'list' => ['id', 'title', 'category_id', 'starts_at', 'venue_name', 'source_id', 'status', 'is_featured'],
                                       'search' => ['title', 'venue_name', 'uid']],
            'event_categories'     => ['label' => 'Catégories', 'roles' => $editor],
            'event_schedule_items' => ['label' => 'Programme (horaires)', 'roles' => $editor],
            'event_participants'   => ['label' => 'Participants', 'roles' => $editor, 'readonly' => true],
        ],
        'Carte & lieux' => [
            'places'           => ['label' => 'Lieux', 'roles' => $editor, 'list' => ['id', 'name', 'category_id', 'city', 'is_partner', 'is_must_see', 'rating_avg', 'status'],
                                   'search' => ['name', 'address', 'keywords', 'uid']],
            'place_categories' => ['label' => 'Catégories', 'roles' => $editor],
            'place_reviews'    => ['label' => 'Avis', 'roles' => $both, 'list' => ['id', 'place_id', 'user_id', 'rating', 'comment', 'status', 'created_at'], 'search' => ['comment']],
        ],
        'Explorer' => [
            'explore_sections'     => ['label' => 'Rubriques', 'roles' => $editor],
            'explore_items'        => ['label' => 'À découvrir', 'roles' => $editor, 'list' => ['id', 'title', 'section_id', 'link_type', 'sort_order', 'status'], 'search' => ['title', 'subtitle']],
            'explore_highlights'   => ['label' => 'À la une', 'roles' => $editor, 'search' => ['title']],
            'explore_stories'      => ['label' => 'Stories', 'roles' => $editor, 'search' => ['title']],
            'explore_story_slides' => ['label' => 'Pages de stories', 'roles' => $editor],
            'offers'               => ['label' => 'Bons plans / exclusivités', 'roles' => $editor, 'list' => ['id', 'title', 'partner_name', 'discount_label', 'claims_count', 'ends_at', 'status'],
                                       'search' => ['title', 'partner_name', 'code']],
            'offer_claims'         => ['label' => 'Utilisations des bons plans', 'roles' => $editor, 'readonly' => true],
        ],
        'Logement' => [
            'housing_listings'         => ['label' => 'Annonces', 'roles' => $editor, 'list' => ['id', 'title', 'transaction', 'price_cents', 'district_label', 'source_id', 'status'],
                                           'search' => ['title', 'district_label', 'uid']],
            'housing_images'           => ['label' => 'Photos', 'roles' => $editor],
            'housing_contact_requests' => ['label' => 'Demandes de contact', 'roles' => $editor, 'list' => ['id', 'listing_id', 'name', 'email', 'status', 'created_at'], 'search' => ['name', 'email']],
        ],
        'Emploi' => [
            'job_offers'       => ['label' => 'Offres', 'roles' => $editor, 'list' => ['id', 'title', 'company_name', 'contract', 'city', 'expires_at', 'status'], 'search' => ['title', 'company_name', 'uid']],
            'job_categories'   => ['label' => 'Catégories', 'roles' => $editor],
            'job_images'       => ['label' => 'Images', 'roles' => $editor],
            'job_applications' => ['label' => 'Candidatures', 'roles' => $editor, 'list' => ['id', 'offer_id', 'name', 'email', 'status', 'created_at'], 'search' => ['name', 'email']],
        ],
        'Services' => [
            'service_providers'      => ['label' => 'Professionnels', 'roles' => $editor, 'list' => ['id', 'name', 'category_id', 'city', 'rating_avg', 'status'], 'search' => ['name', 'keywords', 'uid']],
            'service_categories'     => ['label' => 'Catégories', 'roles' => $editor],
            'service_quote_requests' => ['label' => 'Demandes de devis', 'roles' => $editor, 'list' => ['id', 'provider_id', 'name', 'email', 'status', 'created_at'], 'search' => ['name', 'email']],
            'service_reviews'        => ['label' => 'Avis', 'roles' => $both, 'search' => ['comment']],
        ],
        'Petites annonces' => [
            'classifieds'           => ['label' => 'Annonces', 'roles' => $both, 'list' => ['id', 'title', 'user_id', 'price_cents', 'reports_count', 'status', 'published_at'], 'search' => ['title', 'uid']],
            'classified_categories' => ['label' => 'Catégories', 'roles' => $editor],
            'classified_media'      => ['label' => 'Photos', 'roles' => $both],
            'classified_reports'    => ['label' => 'Signalements', 'roles' => $moderator],
        ],
        'Communauté' => [
            'community_posts'    => ['label' => 'Publications', 'roles' => $moderator, 'list' => ['id', 'user_id', 'type', 'body', 'likes_count', 'reports_count', 'status', 'created_at'], 'search' => ['body', 'uid']],
            'community_comments' => ['label' => 'Commentaires', 'roles' => $moderator, 'search' => ['body']],
            'community_reports'  => ['label' => 'Signalements', 'roles' => $moderator],
            'community_poll_options' => ['label' => 'Options de sondage', 'roles' => $moderator],
        ],
        'Messagerie' => [
            'conversation_reports' => ['label' => 'Signalements', 'roles' => $moderator],
            // Vie privée : seuls les messages des conversations signalées sont visibles
            'messages'             => ['label' => 'Messages signalés', 'roles' => $moderator, 'can_create' => false, 'search' => ['body'],
                                       'where' => 'conversation_id IN (SELECT conversation_id FROM conversation_reports)'],
            'push_outbox'          => ['label' => 'Notifications push', 'readonly' => true],
        ],
        'Mobilité' => [
            'mobility_destinations' => ['label' => 'Destinations', 'roles' => $editor],
        ],
        'Sources & imports' => [
            'content_sources' => ['label' => 'Sources (partenaires, imports)', 'roles' => $editor],
            'import_runs'     => ['label' => 'Historique des imports', 'roles' => $editor, 'readonly' => true],
        ],
        'Administration' => [
            'admin_users'     => ['label' => 'Administrateurs', 'list' => ['id', 'name', 'email', 'role', 'status', 'last_login_at'], 'search' => ['name', 'email'],
                                  'password' => 'password_hash', 'readonly_columns' => ['last_login_at', 'last_login_ip']],
            'admin_audit_log' => ['label' => 'Journal d\'audit', 'readonly' => true, 'search' => ['resource', 'record_key', 'action']],
        ],
    ],
];
