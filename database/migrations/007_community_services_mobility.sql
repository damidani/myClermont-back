-- ---------------------------------------------------------------------------
-- Médias publics + modules Community, Services, Mobility
-- ---------------------------------------------------------------------------

-- Photos / vidéos envoyées par les utilisateurs (fichiers dans public/media)
CREATE TABLE IF NOT EXISTS media (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid          CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind         ENUM('image','video') NOT NULL,
    mime         VARCHAR(40)  CHARACTER SET ascii NOT NULL,
    device_id    BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NULL,
    size_bytes   INT UNSIGNED NOT NULL,
    width        SMALLINT UNSIGNED NULL,
    height       SMALLINT UNSIGNED NULL,
    path         VARCHAR(120) CHARACTER SET ascii NOT NULL COMMENT 'Relatif à public/media',
    attached_at  DATETIME     NULL,
    expires_at   DATETIME     NOT NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_uid (uid),
    KEY idx_media_expires (attached_at, expires_at),
    CONSTRAINT fk_media_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT fk_media_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===================== Communauté =====================

CREATE TABLE IF NOT EXISTS community_posts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid             CHAR(36)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    type            ENUM('text','poll','photos','photo','video') NOT NULL,
    body            TEXT      NULL COMMENT 'Texte, question du sondage ou description',
    neighborhood_id SMALLINT UNSIGNED NULL COMMENT 'Quartier affiché (celui de l''auteur à la publication)',
    badge_label     VARCHAR(30) NULL COMMENT 'ex. « DÉMO »',
    media_caption   VARCHAR(60) NULL COMMENT 'ex. « Photo d''illustration »',
    likes_count     INT UNSIGNED NOT NULL DEFAULT 0,
    comments_count  INT UNSIGNED NOT NULL DEFAULT 0,
    reports_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('visible','hidden','deleted') NOT NULL DEFAULT 'visible'
                    COMMENT 'hidden = masqué automatiquement après signalements, en attente de modération',
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_posts_uid (uid),
    KEY idx_posts_feed (status, id),
    KEY idx_posts_type (status, type, id),
    KEY idx_posts_user (user_id, id),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_post_media (
    post_id   BIGINT UNSIGNED NOT NULL,
    media_id  BIGINT UNSIGNED NOT NULL,
    position  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (post_id, media_id),
    KEY idx_post_media_position (post_id, position),
    CONSTRAINT fk_post_media_post  FOREIGN KEY (post_id)  REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_media_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS community_poll_options (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id     BIGINT UNSIGNED NOT NULL,
    label       VARCHAR(80) NOT NULL,
    position    TINYINT UNSIGNED NOT NULL,
    votes_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_poll_options_post (post_id, position),
    CONSTRAINT fk_poll_options_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_poll_votes (
    post_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    option_id  BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (post_id, user_id) COMMENT 'Un vote par personne et par sondage',
    CONSTRAINT fk_votes_post   FOREIGN KEY (post_id)   REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_option FOREIGN KEY (option_id) REFERENCES community_poll_options (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS community_likes (
    post_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (post_id, user_id),
    KEY idx_likes_user (user_id),
    CONSTRAINT fk_likes_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS community_comments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    post_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    body        VARCHAR(1000) NOT NULL,
    status      ENUM('visible','deleted') NOT NULL DEFAULT 'visible',
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comments_uid (uid),
    KEY idx_comments_post (post_id, status, id),
    CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_reports (
    post_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    reason     ENUM('spam','harassment','hate','inappropriate','false_info','other') NOT NULL,
    details    VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (post_id, user_id),
    CONSTRAINT fk_reports_post FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Services (professionnels) =====================

CREATE TABLE IF NOT EXISTS service_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    name        VARCHAR(60) NOT NULL,
    icon        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO service_categories (slug, name, icon, sort_order) VALUES
    ('maison-travaux',    'Maison & travaux',     'hammer',     10),
    ('services-domicile', 'Services à domicile',  'house',      20),
    ('informatique',      'Informatique',         'desktop',    30),
    ('sport-bien-etre',   'Sport & bien-être',    'running',    40),
    ('beaute-sante',      'Beauté & santé',       'heart',      50),
    ('cours-formation',   'Cours & formation',    'school',     60),
    ('animaux',           'Animaux',              'paw',        70),
    ('evenementiel',      'Événementiel',         'camera',     80),
    ('auto-mobilite',     'Auto & mobilité',      'car',        90);

CREATE TABLE IF NOT EXISTS service_providers (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    external_id      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_url     VARCHAR(500) NULL,
    content_hash     CHAR(40)     CHARACTER SET ascii NULL,
    last_synced_at   DATETIME     NULL,
    owner_user_id    BIGINT UNSIGNED NULL COMMENT 'Compte du professionnel (fiche revendiquée), pour plus tard',

    category_id      SMALLINT UNSIGNED NOT NULL,
    name             VARCHAR(160) NOT NULL,
    summary          VARCHAR(300) NULL COMMENT 'Texte de la carte',
    description      TEXT         NULL COMMENT '« À propos »',
    services         JSON         NULL COMMENT '« Prestations proposées » : liste de chaînes',
    keywords         VARCHAR(500) NULL COMMENT 'Mots-clés de recherche (métiers)',
    logo_url         VARCHAR(500) NULL,
    cover_url        VARCHAR(500) NULL,
    hours_label      VARCHAR(120) NULL COMMENT 'ex. « Lun–Ven · 8h–18h »',
    price_label      VARCHAR(80)  NULL COMMENT 'ex. « Sur devis », « À partir de 45 € »',
    city             VARCHAR(100) NOT NULL DEFAULT 'Clermont-Ferrand',
    neighborhood_id  SMALLINT UNSIGNED NULL,
    latitude         DECIMAL(9,6) NULL,
    longitude        DECIMAL(9,6) NULL,
    service_radius_km DECIMAL(5,1) NULL COMMENT 'Zone d''intervention',
    phone            VARCHAR(30)  NULL COMMENT 'Affiché (bouton Appeler) si renseigné',
    website_url      VARCHAR(500) NULL,

    -- Avis vérifiés (calculés) ; display_rating = note indicative tant qu'il n'y a pas d'avis (démo, import)
    rating_avg       DECIMAL(2,1) NULL,
    rating_count     INT UNSIGNED NOT NULL DEFAULT 0,
    display_rating   DECIMAL(2,1) NULL,
    display_rating_label VARCHAR(60) NULL COMMENT 'ex. « note de démonstration »',

    badge_label      VARCHAR(30)  NULL,
    notice           VARCHAR(255) NULL,
    contact_email    VARCHAR(191) NULL COMMENT 'Destinataire des demandes de devis (jamais exposé)',
    status           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_providers_uid (uid),
    UNIQUE KEY uq_providers_external (source_id, external_id),
    KEY idx_providers_category (status, category_id),
    KEY idx_providers_rating (status, rating_avg),
    CONSTRAINT fk_providers_source FOREIGN KEY (source_id) REFERENCES content_sources (id),
    CONSTRAINT fk_providers_category FOREIGN KEY (category_id) REFERENCES service_categories (id),
    CONSTRAINT fk_providers_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL,
    CONSTRAINT fk_providers_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Demander un devis »
CREATE TABLE IF NOT EXISTS service_quote_requests (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid          CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_id  BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NULL,
    device_id    BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(191) NOT NULL,
    phone        VARCHAR(30)  NULL,
    postal_code  CHAR(5)      CHARACTER SET ascii NULL,
    preferred_date DATE       NULL,
    message      TEXT         NOT NULL,
    status       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error   VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL,
    sent_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quotes_uid (uid),
    KEY idx_quotes_queue (status, attempts, id),
    KEY idx_quotes_dedupe (device_id, provider_id, created_at),
    KEY idx_quotes_user (user_id, provider_id),
    CONSTRAINT fk_quotes_provider FOREIGN KEY (provider_id) REFERENCES service_providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_quotes_user     FOREIGN KEY (user_id)     REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_quotes_device   FOREIGN KEY (device_id)   REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Avis MyClermont » : seulement des personnes ayant demandé un devis à ce professionnel
CREATE TABLE IF NOT EXISTS service_reviews (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    comment     VARCHAR(1500) NULL,
    status      ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reviews_uid (uid),
    UNIQUE KEY uq_reviews_one_per_user (provider_id, user_id),
    KEY idx_reviews_provider (provider_id, status, id),
    CONSTRAINT fk_reviews_provider FOREIGN KEY (provider_id) REFERENCES service_providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user     FOREIGN KEY (user_id)     REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Mobilité =====================

-- « Destinations rapides »
CREATE TABLE IF NOT EXISTS mobility_destinations (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    name        VARCHAR(80) NOT NULL,
    address     VARCHAR(200) NULL,
    icon        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    latitude    DECIMAL(9,6) NOT NULL,
    longitude   DECIMAL(9,6) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_destinations_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coordonnées approximatives : à vérifier avant la mise en production
INSERT IGNORE INTO mobility_destinations (slug, name, address, icon, latitude, longitude, sort_order) VALUES
    ('gare-sncf',   'Gare SNCF',             'Avenue de l''Union Soviétique, Clermont-Ferrand', 'train',    45.778900, 3.100100, 10),
    ('chu-montpied','CHU Gabriel-Montpied',  '58 rue Montalembert, Clermont-Ferrand',           'hospital', 45.762300, 3.098000, 20),
    ('aeroport',    'Aéroport',              'Aulnat',                                          'plane',    45.786100, 3.169200, 30),
    ('place-jaude', 'Place de Jaude',        'Place de Jaude, Clermont-Ferrand',                'pin',      45.777300, 3.081600, 40),
    ('campus-cezeaux','Campus des Cézeaux',  'Aubière',                                         'school',   45.759600, 3.111500, 50),
    ('stade-michelin','Stade Marcel-Michelin','Clermont-Ferrand',                               'stadium',  45.789900, 3.116200, 60);
