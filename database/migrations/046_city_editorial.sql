-- Ma Ville : News par MyClermont (372b9b1b), Associations à la place de Logement (e3551d70),
-- Balade du jour (4220897f §2). Contenus saisis dans /admin, ou importés (associations : JOAFE, open data).

CREATE TABLE IF NOT EXISTS news_items (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind             ENUM('article','video') NOT NULL DEFAULT 'article',
    category         VARCHAR(40)  NOT NULL DEFAULT 'Actu' COMMENT 'Thème : Actu, Culture, Sport, Food, Nature…',
    title            VARCHAR(160) NOT NULL,
    summary          VARCHAR(500) NULL COMMENT 'Chapô',
    body             MEDIUMTEXT   NULL COMMENT 'Texte de l''article (vide pour une vidéo)',
    image_url        VARCHAR(500) NULL COMMENT 'Couverture (https)',
    video_url        VARCHAR(500) NULL COMMENT 'Vidéo lisible en direct : mp4 ou HLS .m3u8 (pas une page YouTube)',
    author           VARCHAR(80)  NULL COMMENT 'Défaut : MyClermont',
    reading_minutes  TINYINT UNSIGNED NULL,
    status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_news_uid (uid),
    KEY idx_news_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS associations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    external_id      VARCHAR(64)  CHARACTER SET ascii NULL COMMENT 'Numéro RNA (import JOAFE)',
    content_hash     CHAR(64)     CHARACTER SET ascii NULL,
    last_synced_at   DATETIME     NULL,
    name             VARCHAR(200) NOT NULL,
    category         VARCHAR(40)  NOT NULL DEFAULT 'Vie locale' COMMENT 'Sport, Culture, Solidarité, Environnement, Jeunesse, Santé, Vie locale',
    district         VARCHAR(100) NULL COMMENT 'Quartier ou commune',
    icon             VARCHAR(40)  CHARACTER SET ascii NULL COMMENT 'Symbole SF',
    description      TEXT         NULL,
    activities       JSON         NULL COMMENT '["Bénévolat", "Aide alimentaire"]',
    address          VARCHAR(200) NULL,
    email            VARCHAR(190) NULL,
    phone            VARCHAR(30)  NULL,
    website_url      VARCHAR(500) NULL,
    latitude         DECIMAL(9,6) NULL,
    longitude        DECIMAL(9,6) NULL,
    created_on       DATE         NULL COMMENT 'Date de déclaration',
    status           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_associations_uid (uid),
    UNIQUE KEY uq_associations_source (source_id, external_id),
    KEY idx_associations_list (status, category),
    FULLTEXT KEY ft_associations (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itineraries (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title            VARCHAR(120) NOT NULL,
    subtitle         VARCHAR(160) NULL,
    stops            JSON         NOT NULL COMMENT '[{"name", "latitude", "longitude", "placeId"?}] : 2 à 5 arrêts, dans l''ordre',
    featured_on      DATE         NULL COMMENT 'Balade du jour à cette date ; sinon rotation quotidienne des balades publiées',
    status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_itineraries_uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO content_sources (slug, name, kind, website_url, attribution, contact_mode, auto_publish, is_active, created_at)
    VALUES ('joafe-associations', 'Journal officiel des associations', 'import', 'https://www.journal-officiel.gouv.fr/pages/associations-recherche/',
            'Source : Journal officiel (JOAFE), Licence Ouverte', 'external', 1, 1, UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE attribution = VALUES(attribution);

-- Balade du centre historique, à partir des incontournables déjà en ligne (positions réelles des fiches)
INSERT IGNORE INTO itineraries (uid, title, subtitle, stops, status, created_at, updated_at)
SELECT 'b1a1ade0-0000-4000-8000-000000000001', 'Le cœur historique de Clermont', 'De la place de Jaude à la cathédrale, puis le jardin Lecoq',
       JSON_ARRAY(
           JSON_OBJECT('name', j.name, 'latitude', j.latitude, 'longitude', j.longitude, 'placeId', j.uid),
           JSON_OBJECT('name', c.name, 'latitude', c.latitude, 'longitude', c.longitude, 'placeId', c.uid),
           JSON_OBJECT('name', l.name, 'latitude', l.latitude, 'longitude', l.longitude, 'placeId', l.uid)),
       'published', UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM places j, places c, places l
WHERE j.uid = 'af506751-8902-485e-919d-a21dbc7123dc' AND c.uid = '4e16b576-c7dc-4fad-835a-8957d5b2dba3' AND l.uid = 'b4c18e02-1416-4f71-8e37-7197e26b828c';

-- « À découvrir » : les éléments de démonstration ne sont plus diffusés (l'app est 100 % back)
UPDATE explore_items SET status = 'draft' WHERE subtitle LIKE '%démo%';

-- GET /explore/selections : texte de présentation des éléments « À découvrir »
ALTER TABLE explore_items ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER subtitle;
