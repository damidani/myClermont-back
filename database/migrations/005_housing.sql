-- ---------------------------------------------------------------------------
-- Module Housing (Logement) : annonces location / achat / colocation
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS housing_listings (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    external_id      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_url     VARCHAR(500) NULL COMMENT 'Annonce d''origine : le bouton Contacter y renvoie si la source est en mode external',
    content_hash     CHAR(40)     CHARACTER SET ascii NULL,
    last_synced_at   DATETIME     NULL,

    transaction      ENUM('rent','sale','colocation') NOT NULL,
    property_type    ENUM('apartment','house','studio','room','loft','other') NOT NULL DEFAULT 'apartment',
    title            VARCHAR(160) NOT NULL,
    description      TEXT         NULL,
    price_cents      INT UNSIGNED NOT NULL COMMENT 'Loyer mensuel (rent, colocation) ou prix de vente (sale)',
    charges_cents    INT UNSIGNED NULL COMMENT 'Charges mensuelles',
    charges_included TINYINT(1)   NULL,
    surface_m2       DECIMAL(7,2) NULL,
    rooms            TINYINT UNSIGNED NULL,
    bedrooms         TINYINT UNSIGNED NULL,
    furnished        TINYINT(1)   NOT NULL DEFAULT 0,
    floor            SMALLINT     NULL,
    energy_class     CHAR(1)      CHARACTER SET ascii NULL COMMENT 'DPE A à G',
    available_from   DATE         NULL,
    features         VARCHAR(500) CHARACTER SET ascii NULL COMMENT 'Codes séparés par des virgules (config/housing.php)',

    district_label   VARCHAR(100) NULL COMMENT 'ex. « Centre-ville »',
    neighborhood_id  SMALLINT UNSIGNED NULL,
    city             VARCHAR(100) NOT NULL DEFAULT 'Clermont-Ferrand',
    postal_code      CHAR(5)      CHARACTER SET ascii NULL,
    latitude         DECIMAL(9,6) NULL COMMENT 'Position approximative (jamais l''adresse exacte)',
    longitude        DECIMAL(9,6) NULL,

    image_caption    VARCHAR(120) NULL COMMENT 'ex. « Photos d''illustration · démo »',
    notice           VARCHAR(255) NULL,

    -- Coordonnées de l'annonceur : JAMAIS renvoyées par l'API, utilisées pour transmettre les demandes
    contact_name     VARCHAR(120) NULL,
    contact_email    VARCHAR(191) NULL,
    contact_phone    VARCHAR(30)  NULL,

    status           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_housing_uid (uid),
    UNIQUE KEY uq_housing_external (source_id, external_id),
    KEY idx_housing_recent (status, published_at, id),
    KEY idx_housing_transaction (status, transaction, published_at, id),
    KEY idx_housing_price (status, transaction, price_cents, id),
    KEY idx_housing_geo (status, latitude, longitude),
    CONSTRAINT fk_housing_source FOREIGN KEY (source_id) REFERENCES content_sources (id),
    CONSTRAINT fk_housing_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS housing_images (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id  BIGINT UNSIGNED NOT NULL,
    url         VARCHAR(500) NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_housing_images_listing (listing_id, position),
    CONSTRAINT fk_housing_images_listing FOREIGN KEY (listing_id) REFERENCES housing_listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formulaire « Contacter l'annonceur » : demandes stockées puis transmises par e-mail (cron)
CREATE TABLE IF NOT EXISTS housing_contact_requests (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    listing_id  BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NULL,
    device_id   BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(191) NOT NULL,
    phone       VARCHAR(30)  NULL,
    message     TEXT         NOT NULL,
    status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error  VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL,
    sent_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_uid (uid),
    KEY idx_contact_queue (status, attempts, id),
    KEY idx_contact_dedupe (device_id, listing_id, created_at),
    KEY idx_contact_user (user_id, created_at),
    CONSTRAINT fk_contact_listing FOREIGN KEY (listing_id) REFERENCES housing_listings (id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_device  FOREIGN KEY (device_id)  REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
