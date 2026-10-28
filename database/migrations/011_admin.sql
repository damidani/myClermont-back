-- ---------------------------------------------------------------------------
-- Back-office web (/admin) : comptes administrateurs, sessions, journal d'audit.
-- Indépendant des comptes de l'app (table users) : un admin n'est pas un utilisateur mobile.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(191) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL COMMENT 'Argon2id',
    role            ENUM('admin','editor','moderator') NOT NULL DEFAULT 'editor'
                    COMMENT 'admin = tout ; editor = contenus ; moderator = utilisateurs et signalements',
    totp_secret     VARCHAR(255) CHARACTER SET ascii NULL COMMENT 'Double authentification, chiffré avec APP_KEY',
    status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
    id_hash        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 hex du cookie',
    admin_id       INT UNSIGNED NOT NULL,
    csrf_token     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    data           TEXT NULL COMMENT 'Messages flash (JSON)',
    ip             VARCHAR(45) NOT NULL,
    user_agent     VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL,
    last_seen_at   DATETIME NOT NULL,
    expires_at     DATETIME NOT NULL,
    PRIMARY KEY (id_hash),
    KEY idx_admin_sessions_admin (admin_id),
    CONSTRAINT fk_admin_sessions_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id     INT UNSIGNED NULL,
    action       VARCHAR(30) CHARACTER SET ascii NOT NULL COMMENT 'create, update, delete, login, login_failed...',
    resource     VARCHAR(60) CHARACTER SET ascii NULL,
    record_key   VARCHAR(191) NULL,
    changes      MEDIUMTEXT NULL COMMENT 'JSON {colonne: [avant, après]}',
    ip           VARCHAR(45) NOT NULL,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_admin_audit_resource (resource, record_key),
    KEY idx_admin_audit_admin (admin_id, created_at),
    CONSTRAINT fk_admin_audit_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
