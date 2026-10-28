-- ---------------------------------------------------------------------------
-- myClermont — schéma initial
-- MySQL 8.0+ / MariaDB 10.6+ — InnoDB, utf8mb4
-- Toutes les dates sont stockées en UTC.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    uid            CHAR(36)         NOT NULL COMMENT 'Identifiant public (UUID v4), seul exposé par l''API',
    email          VARCHAR(191)     NOT NULL,
    password_hash  VARCHAR(255)     NOT NULL,
    display_name   VARCHAR(100)     NOT NULL,
    status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at     DATETIME         NOT NULL,
    updated_at     DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uid (uid),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    uid               CHAR(36)         CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'X-Device-Id (UUID généré par l''app, minuscules)',
    platform          ENUM('ios','android') NOT NULL,
    token_hash        CHAR(64)         CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 hex du X-Device-Token',
    secret_encrypted  VARCHAR(255)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Secret HMAC chiffré avec APP_KEY (libsodium, base64)',
    status            ENUM('active','blocked') NOT NULL DEFAULT 'active',
    user_id           BIGINT UNSIGNED  NULL COMMENT 'Dernier utilisateur connecté sur l''appareil',
    model             VARCHAR(100)     NULL,
    os_version        VARCHAR(50)      NULL,
    app_version       VARCHAR(30)      NULL,
    push_token        VARCHAR(255)     NULL,
    last_ip           VARCHAR(45)      CHARACTER SET ascii NULL,
    last_seen_at      DATETIME         NULL,
    created_at        DATETIME         NOT NULL,
    updated_at        DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_uid (uid),
    KEY idx_devices_user (user_id),
    CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED  NOT NULL,
    device_id    BIGINT UNSIGNED  NOT NULL,
    token_hash   CHAR(64)         CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 hex du refresh token (jamais stocké en clair)',
    expires_at   DATETIME         NOT NULL,
    revoked_at   DATETIME         NULL,
    replaced_by  BIGINT UNSIGNED  NULL,
    created_at   DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_hash (token_hash),
    KEY idx_refresh_user_device (user_id, device_id, revoked_at),
    KEY idx_refresh_device (device_id, revoked_at),
    KEY idx_refresh_expires (expires_at),
    CONSTRAINT fk_refresh_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
    CONSTRAINT fk_refresh_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repli du cache (nonces, rate-limit) quand APCu n'est pas disponible
CREATE TABLE IF NOT EXISTS cache_entries (
    k           VARCHAR(191)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    value       MEDIUMBLOB    NULL,
    counter     INT UNSIGNED  NOT NULL DEFAULT 0,
    expires_at  INT UNSIGNED  NOT NULL COMMENT 'Timestamp Unix',
    PRIMARY KEY (k),
    KEY idx_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
