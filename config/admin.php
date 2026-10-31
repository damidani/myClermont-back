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
    'auto_columns'     => ['created_at', 'updated_at', 'deleted_at'],

    // Menu rangé par usage : d'abord ce que l'app affiche, puis la modération, les demandes reçues, les comptes, enfin les réglages.
    // "icon" = emoji du menu ; "hint" = phrase d'aide affichée en tête de liste.
    'groups' => [
        'Contenus de l\'app' => [
            'events'            => ['label' => 'Événements', 'icon' => '🎉', 'roles' => $editor, 'list' => ['id', 'title', 'kind', 'starts_at', 'venue_name', 'status', 'is_featured'],
                                    'search' => ['title', 'venue_name', 'slug'], 'hint' => 'Agenda et carte de l\'app. Une proposition d\'utilisateur arrive en « En attente » : publie-la depuis « À modérer ».'],
            'places'            => ['label' => 'Lieux de la carte', 'icon' => '📍', 'roles' => $editor, 'list' => ['id', 'name', 'app_category', 'city', 'is_partner', 'rating_avg', 'status'],
                                    'search' => ['name', 'address', 'keywords', 'uid']],
            'deals'             => ['label' => 'Bons plans', 'icon' => '🏷️', 'roles' => $editor, 'list' => ['id', 'title', 'merchant', 'category', 'reduced_cents', 'expires_at', 'status'],
                                    'search' => ['title', 'merchant'], 'hint' => 'Bons plans affichés dans Ma Ville. Catégories : restaurant, cafe, bien-etre, sorties, sport.'],
            'job_offers'        => ['label' => 'Offres d\'emploi', 'icon' => '💼', 'roles' => $editor, 'list' => ['id', 'title', 'company_name', 'contract', 'city', 'expires_at', 'status'],
                                    'search' => ['title', 'company_name', 'uid']],
            'housing_listings'  => ['label' => 'Logements', 'icon' => '🏠', 'roles' => $editor, 'list' => ['id', 'cover_url', 'title', 'transaction', 'price_cents', 'district_label', 'status'],
                                    'search' => ['title', 'district_label', 'uid']],
            'service_providers' => ['label' => 'Professionnels', 'icon' => '🛠️', 'roles' => $editor, 'list' => ['id', 'name', 'category_id', 'city', 'rating_avg', 'status'],
                                    'search' => ['name', 'keywords', 'uid']],
            'classifieds'       => ['label' => 'Petites annonces', 'icon' => '🛒', 'roles' => $both, 'list' => ['id', 'title', 'user_id', 'price_cents', 'reports_count', 'status', 'published_at'],
                                    'search' => ['title', 'uid']],
        ],
        'Modération' => [
            'community_posts'    => ['label' => 'Publications', 'icon' => '💬', 'roles' => $moderator, 'list' => ['id', 'user_id', 'type', 'body', 'likes_count', 'reports_count', 'status', 'created_at'],
                                     'search' => ['body', 'uid']],
            'community_comments' => ['label' => 'Commentaires', 'icon' => '🗨️', 'roles' => $moderator, 'list' => ['id', 'post_id', 'user_id', 'body', 'status', 'created_at'], 'search' => ['body']],
            'place_reviews'      => ['label' => 'Avis sur les lieux', 'icon' => '⭐', 'roles' => $both, 'list' => ['id', 'place_id', 'place_key', 'user_id', 'rating', 'comment', 'status', 'created_at'],
                                     'search' => ['comment']],
            'reports'            => ['label' => 'Signalements', 'icon' => '🚩', 'roles' => $moderator, 'list' => ['id', 'target_type', 'target_id', 'reason', 'details', 'resolution', 'created_at'],
                                     'can_create' => false],
            'messages'           => ['label' => 'Messages signalés', 'icon' => '✉️', 'roles' => $moderator, 'can_create' => false, 'search' => ['body'],
                                     'where' => 'conversation_id IN (SELECT conversation_id FROM conversation_reports)'],
            'user_blocks'        => ['label' => 'Blocages entre utilisateurs', 'icon' => '⛔', 'roles' => $moderator],
        ],
        'Demandes reçues' => [
            'job_applications'         => ['label' => 'Candidatures', 'icon' => '📨', 'roles' => $editor, 'list' => ['id', 'offer_id', 'name', 'email', 'status', 'created_at'], 'search' => ['name', 'email']],
            'housing_contact_requests' => ['label' => 'Contacts logement', 'icon' => '📨', 'roles' => $editor, 'list' => ['id', 'listing_id', 'name', 'email', 'status', 'created_at'], 'search' => ['name', 'email']],
            'service_quote_requests'   => ['label' => 'Demandes de devis', 'icon' => '📨', 'roles' => $editor, 'list' => ['id', 'provider_id', 'name', 'email', 'status', 'created_at'], 'search' => ['name', 'email']],
            'deal_redemptions'         => ['label' => 'Bons plans utilisés', 'icon' => '🎟️', 'roles' => $editor, 'readonly' => true],
        ],
        'Utilisateurs' => [
            'users'         => ['label' => 'Comptes', 'icon' => '👤', 'roles' => $moderator, 'list' => ['id', 'first_name', 'email', 'city', 'role', 'status', 'created_at'],
                                'search' => ['first_name', 'display_name', 'email', 'uid'], 'password' => 'password_hash', 'readonly_columns' => ['referral_code', 'consented_at']],
            'subscriptions' => ['label' => 'Abonnements My Clermont+', 'icon' => '💎', 'roles' => $moderator, 'readonly' => true],
            'devices'       => ['label' => 'Appareils', 'icon' => '📱', 'roles' => $moderator, 'list' => ['id', 'platform', 'model', 'app_version', 'user_id', 'status', 'last_seen_at'],
                                'search' => ['uid', 'model'], 'can_create' => false],
        ],
        'Canal agents' => [
            'agent_messages' => ['label' => 'Messages entre Claude', 'icon' => '🤖', 'list' => ['id', 'sender', 'recipient', 'kind', 'title', 'status', 'created_at'], 'search' => ['title', 'body'],
                                 'hint' => 'Demandes et comptes rendus échangés entre le Claude de l\'app et le Claude du back-end (app-integration/CANAL_AGENTS.md).'],
            'agent_presence' => ['label' => 'Dernières connexions', 'icon' => '🕒', 'readonly' => true],
        ],
        'Réglages' => [
            'place_photos'          => ['label' => 'Photos des lieux', 'roles' => $editor],
            'event_categories'      => ['label' => 'Catégories d\'événements', 'roles' => $editor],
            'event_schedule_items'  => ['label' => 'Programmes d\'événements', 'roles' => $editor],
            'place_categories'      => ['label' => 'Catégories de lieux', 'roles' => $editor],
            'job_categories'        => ['label' => 'Catégories d\'emploi', 'roles' => $editor],
            'job_images'            => ['label' => 'Images des offres', 'roles' => $editor],
            'housing_images'        => ['label' => 'Photos des logements', 'roles' => $editor],
            'service_categories'    => ['label' => 'Catégories de services', 'roles' => $editor],
            'service_reviews'       => ['label' => 'Avis sur les professionnels', 'roles' => $both, 'search' => ['comment']],
            'classified_categories' => ['label' => 'Catégories d\'annonces', 'roles' => $editor],
            'classified_media'      => ['label' => 'Photos des annonces', 'roles' => $both],
            'neighborhoods'         => ['label' => 'Quartiers', 'roles' => $editor],
            'explore_sections'      => ['label' => 'Explorer : rubriques', 'roles' => $editor],
            'explore_items'         => ['label' => 'Explorer : à découvrir', 'roles' => $editor, 'list' => ['id', 'title', 'section_id', 'link_type', 'sort_order', 'status'], 'search' => ['title', 'subtitle']],
            'explore_highlights'    => ['label' => 'Explorer : à la une', 'roles' => $editor, 'search' => ['title']],
            'explore_stories'       => ['label' => 'Explorer : stories', 'roles' => $editor, 'search' => ['title']],
            'explore_story_slides'  => ['label' => 'Explorer : pages de stories', 'roles' => $editor],
            'offers'                => ['label' => 'Explorer : exclusivités', 'roles' => $editor, 'search' => ['title', 'partner_name', 'code']],
            'mobility_destinations' => ['label' => 'Mobilité : destinations', 'roles' => $editor],
            'content_sources'       => ['label' => 'Sources et imports', 'roles' => $editor],
            'import_runs'           => ['label' => 'Historique des imports', 'roles' => $editor, 'readonly' => true],
            'push_outbox'           => ['label' => 'Notifications envoyées', 'readonly' => true],
            'ai_questions'          => ['label' => 'Questions à Clermont IA', 'readonly' => true],
            'user_badges'           => ['label' => 'Badges obtenus', 'roles' => $moderator, 'search' => ['badge_code']],
            'point_transactions'    => ['label' => 'Historique des points', 'roles' => $moderator, 'readonly' => true],
            'community_poll_options'=> ['label' => 'Options de sondage', 'roles' => $moderator],
            'admin_users'           => ['label' => 'Administrateurs', 'list' => ['id', 'name', 'email', 'role', 'status', 'last_login_at'], 'search' => ['name', 'email'],
                                        'password' => 'password_hash', 'readonly_columns' => ['last_login_at', 'last_login_ip']],
            'admin_audit_log'       => ['label' => 'Journal des actions', 'readonly' => true, 'search' => ['resource', 'record_key', 'action']],
        ],
    ],
];
