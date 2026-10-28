-- ---------------------------------------------------------------------------
-- Module Events (Agenda)
-- Dates stockées en UTC ; l'API les renvoie en heure de Paris avec le décalage.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS event_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40)  CHARACTER SET ascii NOT NULL,
    name        VARCHAR(60)  NOT NULL,
    icon        VARCHAR(40)  CHARACTER SET ascii NOT NULL COMMENT 'Clé d''icône interprétée par l''app',
    color       CHAR(7)      CHARACTER SET ascii NOT NULL COMMENT '#RRGGBB',
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO event_categories (slug, name, icon, color, sort_order) VALUES
    ('sport',       'Sport',        'sport',      '#D63A5E', 10),
    ('musique',     'Musique',      'music',      '#8E3B8E', 20),
    ('culture',     'Culture',      'museum',     '#5B3E96', 30),
    ('festival',    'Festival',     'festival',   '#E0702E', 40),
    ('famille',     'Famille',      'family',     '#2E9E6E', 50),
    ('gastronomie', 'Gastronomie',  'food',       '#C9852B', 60),
    ('nature',      'Nature',       'nature',     '#3E8E41', 70),
    ('marche',      'Marché',       'market',     '#B5542E', 80),
    ('conference',  'Conférence',   'talk',       '#3A6EA5', 90);

CREATE TABLE IF NOT EXISTS events (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid               CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    slug              VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'URL de partage',
    category_id       SMALLINT UNSIGNED NOT NULL,
    title             VARCHAR(160) NOT NULL,
    description       TEXT         NULL COMMENT 'Section « À propos »',
    image_url         VARCHAR(500) NULL,
    image_caption     VARCHAR(120) NULL COMMENT 'ex. « Visuel d''illustration · démo »',
    starts_at         DATETIME     NOT NULL COMMENT 'UTC',
    ends_at           DATETIME     NOT NULL COMMENT 'UTC (durée = ends_at - starts_at)',
    venue_name        VARCHAR(160) NOT NULL COMMENT 'ex. Stade Marcel-Michelin',
    city              VARCHAR(100) NOT NULL DEFAULT 'Clermont-Ferrand',
    address           VARCHAR(255) NULL,
    latitude          DECIMAL(9,6) NULL,
    longitude         DECIMAL(9,6) NULL,
    neighborhood_id   SMALLINT UNSIGNED NULL,
    price_label       VARCHAR(80)  NULL COMMENT 'Texte libre : « Gratuit », « À partir de 15 € », « Billetterie à confirmer »',
    price_min_cents   INT UNSIGNED NULL COMMENT 'NULL = inconnu, 0 = gratuit (tri / filtre)',
    ticket_url        VARCHAR(500) NULL,
    capacity          INT UNSIGNED NULL COMMENT 'NULL = illimité',
    participants_count INT UNSIGNED NOT NULL DEFAULT 0,
    tags              VARCHAR(255) CHARACTER SET ascii NULL COMMENT 'Séparés par des virgules (gamification : volcan, gourmand...)',
    notice            VARCHAR(255) NULL COMMENT 'Mention en bas de fiche (ex. « Événement fictif… »)',
    status            ENUM('draft','published','cancelled') NOT NULL DEFAULT 'draft',
    is_featured       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL,
    updated_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_uid (uid),
    UNIQUE KEY uq_events_slug (slug),
    -- Index principal de l'agenda : événements publiés non terminés, triés par date
    KEY idx_events_agenda (status, ends_at, starts_at, id),
    KEY idx_events_category (category_id, status, ends_at, starts_at),
    CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES event_categories (id),
    CONSTRAINT fk_events_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section « Programme »
CREATE TABLE IF NOT EXISTS event_schedule_items (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id  BIGINT UNSIGNED NOT NULL,
    time      TIME         NOT NULL COMMENT 'Heure locale (Paris)',
    label     VARCHAR(160) NOT NULL,
    position  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_schedule_event (event_id, position),
    CONSTRAINT fk_schedule_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bouton « Je participe »
CREATE TABLE IF NOT EXISTS event_participants (
    event_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_id, user_id),
    KEY idx_participants_user (user_id, event_id),
    CONSTRAINT fk_participants_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE
) ENGINE=InnoDB;
