-- ---------------------------------------------------------------------------
-- Modules Classifieds (petites annonces) et Messaging (messagerie)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS classified_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    name        VARCHAR(60) NOT NULL,
    icon        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    color       CHAR(7)     CHARACTER SET ascii NOT NULL COMMENT 'Couleur du visuel par défaut (annonce sans photo)',
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classified_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO classified_categories (slug, name, icon, color, sort_order) VALUES
    ('maison-deco',  'Maison & déco',     'sofa',      '#4FA77E', 10),
    ('high-tech',    'High-tech',         'laptop',    '#4A6FD6', 20),
    ('mode',         'Mode',              'tshirt',    '#C2508A', 30),
    ('sport-loisirs','Sport & loisirs',   'hiking',    '#E2703A', 40),
    ('bricolage',    'Bricolage & jardin','hammer',    '#3E9A6A', 50),
    ('enfants',      'Enfants',           'teddy',     '#E0A43A', 60),
    ('livres-media', 'Livres & médias',   'book',      '#7B4FD6', 70),
    ('vehicules',    'Vélos & mobilité',  'bike',      '#3A8EA5', 80),
    ('dons',         'Dons & gratuit',    'gift',      '#4A9A9A', 90),
    ('autre',        'Autre',             'tag',       '#6B7A8A', 999);

CREATE TABLE IF NOT EXISTS classifieds (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id          BIGINT UNSIGNED NULL COMMENT 'Vendeur (NULL pour une annonce partenaire / importée)',
    source_id        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    external_id      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_url     VARCHAR(500) NULL,
    content_hash     CHAR(40)     CHARACTER SET ascii NULL,
    last_synced_at   DATETIME     NULL,

    category_id      SMALLINT UNSIGNED NOT NULL,
    title            VARCHAR(100) NOT NULL,
    description      TEXT         NULL,
    price_cents      INT UNSIGNED NOT NULL DEFAULT 0,
    is_free          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '« Je donne gratuitement »',
    item_condition   ENUM('new','very_good','good','fair','for_parts') NOT NULL DEFAULT 'good',
    location_label   VARCHAR(100) NOT NULL COMMENT '« Quartier / commune »',
    neighborhood_id  SMALLINT UNSIGNED NULL,
    latitude         DECIMAL(9,6) NULL,
    longitude        DECIMAL(9,6) NULL,
    badge_label      VARCHAR(30)  NULL,
    notice           VARCHAR(255) NULL,

    status           ENUM('active','reserved','sold','expired','deleted','hidden') NOT NULL DEFAULT 'active',
    reports_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    published_at     DATETIME NOT NULL,
    expires_at       DATETIME NOT NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_classifieds_uid (uid),
    UNIQUE KEY uq_classifieds_external (source_id, external_id),
    KEY idx_classifieds_recent (status, published_at, id),
    KEY idx_classifieds_category (status, category_id, published_at),
    KEY idx_classifieds_price (status, price_cents, id),
    KEY idx_classifieds_free (status, is_free, published_at),
    KEY idx_classifieds_user (user_id, status),
    CONSTRAINT fk_classifieds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_source FOREIGN KEY (source_id) REFERENCES content_sources (id),
    CONSTRAINT fk_classifieds_category FOREIGN KEY (category_id) REFERENCES classified_categories (id),
    CONSTRAINT fk_classifieds_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classified_media (
    classified_id BIGINT UNSIGNED NOT NULL,
    media_id      BIGINT UNSIGNED NULL COMMENT 'Photo envoyée par l''utilisateur',
    url           VARCHAR(500) NULL COMMENT 'Photo externe (annonce importée)',
    position      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_classified_media (classified_id, position),
    CONSTRAINT fk_classified_media_classified FOREIGN KEY (classified_id) REFERENCES classifieds (id) ON DELETE CASCADE,
    CONSTRAINT fk_classified_media_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classified_reports (
    classified_id BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    reason        ENUM('scam','prohibited','duplicate','wrong_category','offensive','other') NOT NULL,
    details       VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (classified_id, user_id),
    CONSTRAINT fk_classified_reports_c FOREIGN KEY (classified_id) REFERENCES classifieds (id) ON DELETE CASCADE,
    CONSTRAINT fk_classified_reports_u FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Messagerie =====================

-- Une conversation porte sur un « contexte » (une petite annonce aujourd'hui, d'autres modules demain)
CREATE TABLE IF NOT EXISTS conversations (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    context_type         VARCHAR(30) CHARACTER SET ascii NOT NULL COMMENT 'ex. classified',
    context_id           VARCHAR(64) CHARACTER SET ascii NOT NULL COMMENT 'uid de l''élément',
    pair_key             VARCHAR(140) CHARACTER SET ascii NOT NULL COMMENT 'contexte + 2 utilisateurs : une seule conversation par paire et par annonce',
    last_message_id      BIGINT UNSIGNED NULL,
    last_message_at      DATETIME NULL,
    created_at           DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversations_uid (uid),
    UNIQUE KEY uq_conversations_pair (pair_key),
    KEY idx_conversations_context (context_type, context_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_participants (
    conversation_id       BIGINT UNSIGNED NOT NULL,
    user_id               BIGINT UNSIGNED NOT NULL,
    role                  VARCHAR(20) CHARACTER SET ascii NOT NULL COMMENT 'buyer, seller...',
    last_read_message_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unread_count          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dénormalisé : badge de l''onglet Chat sans calcul',
    archived_at           DATETIME NULL COMMENT 'Conversation masquée par cet utilisateur (réapparaît au prochain message)',
    last_activity_at      DATETIME NOT NULL,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_participants_inbox (user_id, archived_at, last_activity_at),
    CONSTRAINT fk_conv_participants_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_participants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id  BIGINT UNSIGNED NOT NULL,
    sender_id        BIGINT UNSIGNED NULL COMMENT 'NULL = message système',
    client_id        VARCHAR(64) CHARACTER SET ascii NULL COMMENT 'Identifiant généré par l''app : un renvoi réseau ne crée pas de doublon',
    body             TEXT NOT NULL,
    has_link         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Contient une URL → avertissement anti-arnaque dans l''app',
    created_at       DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_messages_uid (uid),
    UNIQUE KEY uq_messages_client (conversation_id, sender_id, client_id),
    KEY idx_messages_conversation (conversation_id, id),
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
    blocker_id  BIGINT UNSIGNED NOT NULL,
    blocked_id  BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (blocker_id, blocked_id),
    KEY idx_blocks_blocked (blocked_id),
    CONSTRAINT fk_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_reports (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    reason          ENUM('scam','harassment','spam','other') NOT NULL,
    details         VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_conv_reports_c FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_reports_u FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications push à envoyer (nouveau message...) : file traitée par bin/console push:send
CREATE TABLE IF NOT EXISTS push_outbox (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(120) NOT NULL,
    body        VARCHAR(250) NOT NULL,
    data        JSON NULL COMMENT 'Lien profond pour l''app (ex. conversation)',
    collapse_key VARCHAR(80) CHARACTER SET ascii NULL COMMENT 'Regroupe les notifications d''une même conversation',
    status      ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    sent_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_push_queue (status, id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
