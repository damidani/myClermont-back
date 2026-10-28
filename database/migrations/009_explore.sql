-- ---------------------------------------------------------------------------
-- Module Explore : écran d'accueil éditorial (stories, flash, exclusivités, à découvrir)
-- Liens vers les autres modules : link_type + link_id (+ link_url pour un lien web)
--   link_type : event | housing | job | classified | service | offer | story | post | screen | url
-- ---------------------------------------------------------------------------

-- Puces « Tout · Restaurants · Activités · Bons plans… »
CREATE TABLE IF NOT EXISTS explore_sections (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    name        VARCHAR(60) NOT NULL,
    icon        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_chip     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Affichée dans les puces de filtre',
    PRIMARY KEY (id),
    UNIQUE KEY uq_explore_sections_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO explore_sections (slug, name, icon, sort_order, is_chip) VALUES
    ('restaurants',   'Restaurants',       'fork-knife',  10, 1),
    ('activites',     'Activités',         'hiking',      20, 1),
    ('bons-plans',    'Bons plans',        'tag',         30, 1),
    ('agenda',        'Agenda',            'mic',         40, 1),
    ('culture',       'Culture',           'museum',      50, 1),
    ('logement',      'Logement',          'house',       60, 0),
    ('emploi',        'Emploi & stages',   'briefcase',   70, 0),
    ('annonces',      'Petites annonces',  'tag',         80, 0),
    ('transports',    'Transports',        'bus',         90, 0);

-- « Les stories de Clermont »
CREATE TABLE IF NOT EXISTS explore_stories (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title       VARCHAR(60)  NOT NULL COMMENT '« Les volcans », « Sortir ce soir »',
    subtitle    VARCHAR(80)  NULL COMMENT '« My Clermont · Story démo »',
    cover_url   VARCHAR(500) NOT NULL COMMENT 'Vignette ronde',
    icon        VARCHAR(40)  CHARACTER SET ascii NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    starts_at   DATETIME     NULL,
    ends_at     DATETIME     NULL,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    updated_at  DATETIME     NOT NULL COMMENT 'Une story modifiée redevient « non vue »',
    PRIMARY KEY (id),
    UNIQUE KEY uq_stories_uid (uid),
    KEY idx_stories_published (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS explore_story_slides (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    story_id     BIGINT UNSIGNED NOT NULL,
    position     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    media_type   ENUM('image','video') NOT NULL DEFAULT 'image',
    media_url    VARCHAR(500) NOT NULL,
    duration_ms  INT UNSIGNED NOT NULL DEFAULT 5000 COMMENT 'Durée d''affichage (images) ; vidéo = sa durée',
    title        VARCHAR(80)  NULL COMMENT '« La ville s''anime »',
    body         VARCHAR(300) NULL,
    caption      VARCHAR(60)  NULL COMMENT '« Contenu de démonstration »',
    cta_label    VARCHAR(40)  NULL,
    link_type    VARCHAR(20)  CHARACTER SET ascii NULL,
    link_id      VARCHAR(64)  CHARACTER SET ascii NULL,
    link_url     VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_slides_story (story_id, position),
    CONSTRAINT fk_slides_story FOREIGN KEY (story_id) REFERENCES explore_stories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS explore_story_views (
    story_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    viewed_at  DATETIME NOT NULL,
    PRIMARY KEY (story_id, user_id),
    CONSTRAINT fk_story_views_story FOREIGN KEY (story_id) REFERENCES explore_stories (id) ON DELETE CASCADE,
    CONSTRAINT fk_story_views_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- « Événement Flash » (et autres mises en avant)
CREATE TABLE IF NOT EXISTS explore_highlights (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid             CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    badge_label     VARCHAR(40)  NULL COMMENT '« ÉDITION FLASH · DÉMO »',
    tag_label       VARCHAR(20)  NULL COMMENT '« HOT »',
    title           VARCHAR(80)  NOT NULL,
    subtitle        VARCHAR(140) NULL,
    image_url       VARCHAR(500) NOT NULL,
    location_label  VARCHAR(100) NULL,
    link_type       VARCHAR(20)  CHARACTER SET ascii NULL,
    link_id         VARCHAR(64)  CHARACTER SET ascii NULL,
    link_url        VARCHAR(500) NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    starts_at       DATETIME     NULL,
    ends_at         DATETIME     NULL,
    status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
    PRIMARY KEY (id),
    UNIQUE KEY uq_highlights_uid (uid),
    KEY idx_highlights_published (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Exclusivités My Clermont » : offres négociées avec des partenaires
CREATE TABLE IF NOT EXISTS offers (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    section_id       SMALLINT UNSIGNED NULL,
    partner_name     VARCHAR(120) NOT NULL,
    title            VARCHAR(100) NOT NULL COMMENT '« Brunch au centre-ville »',
    subtitle         VARCHAR(120) NULL COMMENT '« Offre de démonstration »',
    description      TEXT         NULL,
    conditions       TEXT         NULL COMMENT 'Conditions d''utilisation',
    discount_label   VARCHAR(20)  NOT NULL COMMENT '« -30 % », « 1 acheté = 1 offert »',
    icon             VARCHAR(40)  CHARACTER SET ascii NULL,
    image_url        VARCHAR(500) NULL,
    address          VARCHAR(200) NULL,
    latitude         DECIMAL(9,6) NULL,
    longitude        DECIMAL(9,6) NULL,
    redemption_mode  ENUM('code','show','external') NOT NULL DEFAULT 'show'
                     COMMENT 'code = code promo révélé ; show = présenter l''écran en caisse ; external = lien partenaire',
    code             VARCHAR(40)  NULL COMMENT 'Jamais renvoyé dans les listes : seulement après « Profiter de l''offre »',
    external_url     VARCHAR(500) NULL,
    members_only     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Compte requis pour en profiter',
    premium_only     TINYINT(1)   NOT NULL DEFAULT 0,
    max_claims       INT UNSIGNED NULL COMMENT 'Stock total (NULL = illimité)',
    claims_count     INT UNSIGNED NOT NULL DEFAULT 0,
    per_user_limit   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    badge_label      VARCHAR(40)  NULL COMMENT '« Exclusivité My Clermont »',
    notice           VARCHAR(255) NULL,
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    starts_at        DATETIME     NULL,
    ends_at          DATETIME     NULL,
    status           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_offers_uid (uid),
    KEY idx_offers_published (status, sort_order),
    CONSTRAINT fk_offers_source  FOREIGN KEY (source_id)  REFERENCES content_sources (id),
    CONSTRAINT fk_offers_section FOREIGN KEY (section_id) REFERENCES explore_sections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_claims (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id    BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    claimed_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_claims_user (user_id, offer_id),
    CONSTRAINT fk_claims_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE,
    CONSTRAINT fk_claims_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- « À découvrir » : sélection éditoriale qui renvoie vers n'importe quel module
CREATE TABLE IF NOT EXISTS explore_items (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    section_id  SMALLINT UNSIGNED NOT NULL,
    title       VARCHAR(100) NOT NULL,
    subtitle    VARCHAR(120) NULL COMMENT '« Exclusivité · -30 % », « Nature · Balades »',
    icon        VARCHAR(40)  CHARACTER SET ascii NULL COMMENT 'Par défaut : icône de la rubrique',
    image_url   VARCHAR(500) NULL,
    link_type   VARCHAR(20)  CHARACTER SET ascii NULL,
    link_id     VARCHAR(64)  CHARACTER SET ascii NULL,
    link_url    VARCHAR(500) NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    starts_at   DATETIME     NULL,
    ends_at     DATETIME     NULL,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    PRIMARY KEY (id),
    UNIQUE KEY uq_explore_items_uid (uid),
    KEY idx_explore_items (status, section_id, sort_order),
    CONSTRAINT fk_explore_items_section FOREIGN KEY (section_id) REFERENCES explore_sections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
