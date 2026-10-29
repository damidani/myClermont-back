-- ---------------------------------------------------------------------------
-- Spec v1 lot 2 : envois de fichiers par URL signée, bons plans avec code, signalements génériques,
-- avis (1 000 caractères), appareils et thèmes de notifications.
-- ---------------------------------------------------------------------------

-- 1. Envois de fichiers : POST /uploads → PUT uploadUrl → POST /uploads/{id}/confirm
CREATE TABLE IF NOT EXISTS uploads (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'uploadId (= uid du média ou du document créé)',
    user_id       BIGINT UNSIGNED NOT NULL,
    device_id     BIGINT UNSIGNED NOT NULL,
    purpose       ENUM('cv','post','classified','avatar','place') NOT NULL,
    mime          VARCHAR(40) CHARACTER SET ascii NOT NULL COMMENT 'Type annoncé par l''app',
    size_bytes    INT UNSIGNED NOT NULL COMMENT 'Taille annoncée',
    token_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Jeton de l''URL signée (haché)',
    storage_key   VARCHAR(120) CHARACTER SET ascii NULL COMMENT 'Fichier reçu, en attente de confirmation (storage/uploads)',
    received_at   DATETIME NULL,
    confirmed_at  DATETIME NULL,
    url           VARCHAR(500) NULL COMMENT 'URL publique après confirmation (images, vidéos)',
    expires_at    DATETIME NOT NULL COMMENT 'URL d''envoi valable 10 minutes',
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uploads_uid (uid),
    KEY idx_uploads_user (user_id, created_at),
    KEY idx_uploads_expires (confirmed_at, expires_at),
    CONSTRAINT fk_uploads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_uploads_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE media MODIFY kind ENUM('image','video') NOT NULL;

-- 2. Bons plans
CREATE TABLE IF NOT EXISTS deals (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    place_id         BIGINT UNSIGNED NULL COMMENT 'Commerçant',
    merchant         VARCHAR(120) NOT NULL,
    title            VARCHAR(160) NOT NULL,
    area             VARCHAR(100) NULL COMMENT 'Quartier / zone affichée',
    category         VARCHAR(40)  NOT NULL DEFAULT 'autre',
    original_cents   INT UNSIGNED NULL,
    reduced_cents    INT UNSIGNED NULL,
    discount_label   VARCHAR(40)  NULL COMMENT '« -20 % », « 1 acheté = 1 offert »',
    exclusive_plus   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Réservé à My Clermont+',
    image_url        VARCHAR(500) NULL,
    description      TEXT NULL,
    conditions       VARCHAR(1000) NULL,
    starts_at        DATETIME NULL,
    expires_at       DATETIME NULL,
    max_redemptions  INT UNSIGNED NULL COMMENT 'NULL = illimité',
    redemptions_count INT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('draft','pending','published','rejected','archived') NOT NULL DEFAULT 'draft',
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deals_uid (uid),
    KEY idx_deals_list (status, deleted_at, expires_at),
    KEY idx_deals_place (place_id),
    CONSTRAINT fk_deals_place FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deal_redemptions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deal_id       BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    code          CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Code présenté en caisse',
    redeemed_at   DATETIME NOT NULL,
    expires_at    DATETIME NULL,
    validated_at  DATETIME NULL,
    validated_by  BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_redemptions_user (deal_id, user_id),
    UNIQUE KEY uq_redemptions_code (code),
    CONSTRAINT fk_redemptions_deal FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE,
    CONSTRAINT fk_redemptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_redemptions_validator FOREIGN KEY (validated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Signalements (tout contenu publié par les utilisateurs) : traités sous 24 h dans le back-office
CREATE TABLE IF NOT EXISTS reports (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    target_type  ENUM('post','comment','review','classified','user','event','message') NOT NULL,
    target_id    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason       ENUM('spam','scam','offensive','harassment','inappropriate','other') NOT NULL,
    details      VARCHAR(500) NULL,
    resolution   ENUM('removed','dismissed','user_banned') NULL,
    resolved_by  BIGINT UNSIGNED NULL,
    resolved_at  DATETIME NULL,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reports_uid (uid),
    UNIQUE KEY uq_reports_once (user_id, target_type, target_id),
    KEY idx_reports_queue (resolved_at, created_at),
    CONSTRAINT fk_greports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_greports_resolver FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Avis : texte filtré avant publication (la note seule est publiée tout de suite)
ALTER TABLE place_reviews
    MODIFY status ENUM('visible','pending','hidden') NOT NULL DEFAULT 'visible';

-- 5. Appareils : thèmes de notifications, langue
ALTER TABLE devices
    ADD COLUMN locale VARCHAR(10) CHARACTER SET ascii NULL AFTER app_version,
    ADD COLUMN topics VARCHAR(255) CHARACTER SET ascii NULL COMMENT 'Thèmes suivis : events.weekly, deals.new…' AFTER push_token;

-- 6. Comptes bannis (back-office)
ALTER TABLE users MODIFY status ENUM('active','disabled','deleted','banned') NOT NULL DEFAULT 'active';

-- 7. Notifications : thème, appareil sans compte, envoi différé (pas d'annonce entre 22 h et 8 h)
ALTER TABLE push_outbox
    MODIFY user_id BIGINT UNSIGNED NULL,
    ADD COLUMN device_id   BIGINT UNSIGNED NULL COMMENT 'Appareil sans compte (thèmes events.weekly…)' AFTER user_id,
    ADD COLUMN topic       VARCHAR(40) CHARACTER SET ascii NULL AFTER device_id,
    ADD COLUMN thread_id   VARCHAR(80) CHARACTER SET ascii NULL COMMENT 'Regroupement des notifications (thread-id APNs)' AFTER collapse_key,
    ADD COLUMN send_after  DATETIME NULL COMMENT 'Pas avant cette date (heures calmes, regroupement)' AFTER thread_id,
    ADD KEY idx_push_due (status, send_after, id),
    ADD CONSTRAINT fk_push_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE;

-- Une même notification de thème n'est envoyée qu'une fois (ex. rappel d'un événement favori)
CREATE TABLE IF NOT EXISTS push_sent_keys (
    k           VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB;
