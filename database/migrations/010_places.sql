-- ---------------------------------------------------------------------------
-- Module Places (Lieux) : la Carte et les fiches lieux
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS place_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    name        VARCHAR(60) NOT NULL,
    icon        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    color       CHAR(7)     CHARACTER SET ascii NOT NULL COMMENT 'Couleur du repère sur la carte',
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_place_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO place_categories (slug, name, icon, color, sort_order) VALUES
    ('monuments',   'Monuments & places', 'star',        '#D63A5E', 10),
    ('restaurants', 'Restaurants',        'fork-knife',  '#E2703A', 20),
    ('cafes',       'Cafés & salons de thé', 'coffee',   '#8A5A3C', 30),
    ('patisseries', 'Boulangeries & pâtisseries', 'cake', '#C9A27A', 40),
    ('bars',        'Bars & sorties',     'glass',       '#B5427A', 50),
    ('culture',     'Musées & spectacles','masks',       '#7B4FD6', 60),
    ('sport',       'Sport & loisirs',    'running',     '#3A7BD5', 70),
    ('parcs',       'Parcs & nature',     'leaf',        '#3E9A5A', 80),
    ('volcans',     'Volcans & panoramas','mountain',    '#5B6B3A', 90),
    ('shopping',    'Shopping',           'bag',         '#C2508A', 100),
    ('carburant',   'Carburant & recharge','fuel',       '#3A8E8E', 110),
    ('sante',       'Santé',              'cross',       '#2E9E6E', 120),
    ('services-publics', 'Services publics', 'building', '#5B6B7A', 130);

CREATE TABLE IF NOT EXISTS places (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid             CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    external_id     VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_url    VARCHAR(500) NULL,
    content_hash    CHAR(40)     CHARACTER SET ascii NULL,
    last_synced_at  DATETIME     NULL,

    category_id     SMALLINT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    tagline         VARCHAR(160) NULL COMMENT '« Le grand salon à ciel ouvert de Clermont »',
    badge_label     VARCHAR(30)  NULL COMMENT '« À DÉCOUVRIR »',
    description     TEXT         NULL,
    cover_url       VARCHAR(500) NULL,
    latitude        DECIMAL(9,6) NOT NULL,
    longitude       DECIMAL(9,6) NOT NULL,
    address         VARCHAR(200) NULL,
    postal_code     CHAR(5)      CHARACTER SET ascii NULL,
    city            VARCHAR(100) NOT NULL DEFAULT 'Clermont-Ferrand',
    neighborhood_id SMALLINT UNSIGNED NULL,

    -- Horaires : texte affiché + horaires structurés pour calculer « Ouvert maintenant »
    hours_label     VARCHAR(120) NULL COMMENT '« Accès libre · 24 h/24 »',
    opening_hours   JSON         NULL COMMENT '{"always_open":true} ou {"mon":[["09:00","18:00"]],"tue":…}',
    access_label    VARCHAR(160) NULL COMMENT '« Tram A, arrêt Jaude · station C.vélo »',
    parking_label   VARCHAR(160) NULL,
    price_label     VARCHAR(80)  NULL,
    phone           VARCHAR(30)  NULL,
    website_url     VARCHAR(500) NULL,

    facts           JSON NULL COMMENT '« En chiffres » : [{icon, value, label}]',
    highlights      JSON NULL COMMENT '« À ne pas manquer » : [{icon, title, text}]',
    timeline        JSON NULL COMMENT '« Repères » : [{year, text}]',
    tip             VARCHAR(400) NULL COMMENT '« Le conseil My Clermont »',
    sources_label   VARCHAR(300) NULL COMMENT 'Sources affichées en bas de fiche',
    tags            VARCHAR(255) CHARACTER SET ascii NULL COMMENT 'volcan, gourmand… (gamification, recherche)',
    keywords        VARCHAR(500) NULL COMMENT 'Mots-clés de recherche',

    is_partner      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Filtre « Partenaires »',
    is_must_see     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Filtre « Incontournables »',

    rating_avg      DECIMAL(2,1) NULL,
    rating_count    INT UNSIGNED NOT NULL DEFAULT 0,
    display_rating  DECIMAL(2,1) NULL COMMENT 'Note indicative (démo / source) tant qu''il n''y a pas d''avis',
    display_rating_label VARCHAR(60) NULL,

    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_places_uid (uid),
    UNIQUE KEY uq_places_external (source_id, external_id),
    KEY idx_places_status (status, updated_at),
    KEY idx_places_geo (status, latitude, longitude),
    CONSTRAINT fk_places_source FOREIGN KEY (source_id) REFERENCES content_sources (id),
    CONSTRAINT fk_places_category FOREIGN KEY (category_id) REFERENCES place_categories (id),
    CONSTRAINT fk_places_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Donner mon avis »
CREATE TABLE IF NOT EXISTS place_reviews (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    place_id    BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    comment     VARCHAR(1500) NULL,
    status      ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_place_reviews_uid (uid),
    UNIQUE KEY uq_place_reviews_user (place_id, user_id),
    KEY idx_place_reviews (place_id, status, id),
    CONSTRAINT fk_place_reviews_place FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE,
    CONSTRAINT fk_place_reviews_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
