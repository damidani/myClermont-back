-- ---------------------------------------------------------------------------
-- Module Jobs (Emploi & stages) + documents (CV, lettres de motivation)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS job_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) CHARACTER SET ascii NOT NULL,
    name        VARCHAR(60) NOT NULL,
    icon        VARCHAR(40) CHARACTER SET ascii NOT NULL COMMENT 'Icône de la carte (clé interprétée par l''app)',
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO job_categories (slug, name, icon, sort_order) VALUES
    ('tech',           'Informatique & numérique', 'phone',      10),
    ('communication',  'Communication & marketing','megaphone',  20),
    ('commerce',       'Commerce & vente',         'bag',        30),
    ('hotellerie',     'Hôtellerie & restauration','food',       40),
    ('sante',          'Santé & social',           'health',     50),
    ('education',      'Éducation & formation',    'school',     60),
    ('industrie',      'Industrie & production',   'factory',    70),
    ('btp',            'BTP & artisanat',          'hammer',     80),
    ('transport',      'Transport & logistique',   'truck',      90),
    ('administratif',  'Administratif & gestion',  'briefcase', 100),
    ('culture',        'Culture & tourisme',       'museum',    110),
    ('autre',          'Autre',                    'sparkles',  999);

CREATE TABLE IF NOT EXISTS job_offers (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid                CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    external_id        VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_url       VARCHAR(500) NULL COMMENT 'Offre d''origine',
    application_url    VARCHAR(500) NULL COMMENT 'Si renseigné, « Postuler » ouvre ce lien (site carrière / ATS du recruteur)',
    content_hash       CHAR(40)     CHARACTER SET ascii NULL,
    last_synced_at     DATETIME     NULL,

    category_id        SMALLINT UNSIGNED NOT NULL,
    contract           ENUM('cdi','cdd','alternance','stage','freelance','interim','saisonnier') NOT NULL,
    work_mode          ENUM('onsite','hybrid','remote') NOT NULL DEFAULT 'onsite',
    title              VARCHAR(160) NOT NULL,
    company_name       VARCHAR(160) NOT NULL,
    company_logo_url   VARCHAR(500) NULL,
    summary            VARCHAR(300) NULL COMMENT 'Texte court de la carte',
    description        TEXT         NULL COMMENT '« Le poste »',
    missions           JSON         NULL COMMENT '« Tes missions » : liste de chaînes',
    profile            TEXT         NULL COMMENT '« Profil recherché »',
    city               VARCHAR(100) NOT NULL DEFAULT 'Clermont-Ferrand',
    neighborhood_id    SMALLINT UNSIGNED NULL,
    latitude           DECIMAL(9,6) NULL,
    longitude          DECIMAL(9,6) NULL,
    salary_min_cents   INT UNSIGNED NULL,
    salary_max_cents   INT UNSIGNED NULL,
    salary_period      ENUM('year','month','hour') NULL,
    salary_label       VARCHAR(80)  NULL COMMENT 'Remplace le libellé calculé (ex. « Selon profil »)',
    start_date         DATE         NULL,
    duration_label     VARCHAR(60)  NULL COMMENT 'ex. « 6 mois » (CDD, stage)',
    badge_label        VARCHAR(30)  NULL COMMENT 'Pastille libre, ex. « DÉMO »',
    image_caption      VARCHAR(120) NULL COMMENT 'ex. « Découvre l''entreprise »',
    notice             VARCHAR(255) NULL,

    cover_letter_mode  ENUM('none','optional','required') NOT NULL DEFAULT 'optional',
    contact_email      VARCHAR(191) NULL COMMENT 'Destinataire des candidatures (jamais exposé)',

    status             ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at       DATETIME     NULL,
    expires_at         DATETIME     NULL COMMENT 'Archivée automatiquement après cette date',
    created_at         DATETIME     NOT NULL,
    updated_at         DATETIME     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_jobs_uid (uid),
    UNIQUE KEY uq_jobs_external (source_id, external_id),
    KEY idx_jobs_recent (status, published_at, id),
    KEY idx_jobs_contract (status, contract, published_at, id),
    KEY idx_jobs_category (status, category_id, published_at),
    CONSTRAINT fk_jobs_source FOREIGN KEY (source_id) REFERENCES content_sources (id),
    CONSTRAINT fk_jobs_category FOREIGN KEY (category_id) REFERENCES job_categories (id),
    CONSTRAINT fk_jobs_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Galerie « Découvre l'entreprise »
CREATE TABLE IF NOT EXISTS job_images (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id  BIGINT UNSIGNED NOT NULL,
    url       VARCHAR(500) NOT NULL,
    position  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_job_images_offer (offer_id, position),
    CONSTRAINT fk_job_images_offer FOREIGN KEY (offer_id) REFERENCES job_offers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents envoyés par les utilisateurs (stockés hors du dossier public, dans storage/private)
CREATE TABLE IF NOT EXISTS documents (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid          CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind         ENUM('cv','cover_letter') NOT NULL,
    device_id    BIGINT UNSIGNED NOT NULL COMMENT 'Seul l''appareil qui l''a envoyé peut l''utiliser',
    user_id      BIGINT UNSIGNED NULL,
    filename     VARCHAR(150) NOT NULL,
    mime         VARCHAR(60)  CHARACTER SET ascii NOT NULL,
    size_bytes   INT UNSIGNED NOT NULL,
    sha256       CHAR(64)     CHARACTER SET ascii NOT NULL,
    storage_path VARCHAR(255) NOT NULL COMMENT 'Relatif à storage/private',
    attached_at  DATETIME     NULL COMMENT 'NULL = pas encore joint à une candidature',
    expires_at   DATETIME     NOT NULL COMMENT 'Suppression automatique (RGPD)',
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_uid (uid),
    KEY idx_documents_expires (expires_at),
    CONSTRAINT fk_documents_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_user   FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_applications (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid                  CHAR(36)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    offer_id             BIGINT UNSIGNED NOT NULL,
    user_id              BIGINT UNSIGNED NULL,
    device_id            BIGINT UNSIGNED NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    email                VARCHAR(191) NOT NULL,
    phone                VARCHAR(30)  NULL,
    message              TEXT         NULL,
    cv_document_id       BIGINT UNSIGNED NULL,
    cover_document_id    BIGINT UNSIGNED NULL,
    status               ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error           VARCHAR(255) NULL,
    created_at           DATETIME     NOT NULL,
    sent_at              DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_applications_uid (uid),
    KEY idx_applications_queue (status, attempts, id),
    KEY idx_applications_dedupe (device_id, offer_id),
    KEY idx_applications_user (user_id, created_at),
    CONSTRAINT fk_applications_offer  FOREIGN KEY (offer_id)  REFERENCES job_offers (id) ON DELETE CASCADE,
    CONSTRAINT fk_applications_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_applications_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT fk_applications_cv     FOREIGN KEY (cv_document_id)    REFERENCES documents (id) ON DELETE SET NULL,
    CONSTRAINT fk_applications_cover  FOREIGN KEY (cover_document_id) REFERENCES documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
