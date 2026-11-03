-- ---------------------------------------------------------------------------
-- Demande « Ajouts de fonctionnalités » (c06e670d) : badge staff, envois groupés (push / e-mail) avec audience,
-- réglages notifications et e-mails, cartes Apple Wallet (événements, bons plans du compte avec QR code).
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN is_staff    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Membre de l''équipe MyClermont : badge dans la communauté et sur la carte' AFTER role,
    ADD COLUMN staff_title VARCHAR(60) NULL COMMENT '« Équipe MyClermont » par défaut' AFTER is_staff;

CREATE TABLE broadcasts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title        VARCHAR(120) NOT NULL,
    body         VARCHAR(500) NOT NULL,
    email_subject VARCHAR(160) NULL,
    email_body   TEXT NULL,
    channels     VARCHAR(30) CHARACTER SET ascii NOT NULL COMMENT 'push,email',
    audience     LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(audience)),
    target       LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (target IS NULL OR json_valid(target)),
    status       ENUM('brouillon','programme','envoi','envoye','annule') NOT NULL DEFAULT 'brouillon',
    send_at      DATETIME NULL,
    recipients   INT UNSIGNED NOT NULL DEFAULT 0,
    pushed       INT UNSIGNED NOT NULL DEFAULT 0,
    emailed      INT UNSIGNED NOT NULL DEFAULT 0,
    created_by   VARCHAR(120) NOT NULL,
    created_at   DATETIME NOT NULL,
    sent_at      DATETIME NULL,
    UNIQUE KEY uq_broadcasts_uid (uid),
    KEY idx_broadcasts_due (status, send_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
    k          VARCHAR(80) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    v          LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(v)),
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(120) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
