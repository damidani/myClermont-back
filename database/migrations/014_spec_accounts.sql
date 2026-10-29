-- ---------------------------------------------------------------------------
-- Comptes selon la spec v1 : connexion Apple / Google / code par e-mail, profil affiché,
-- rôles, suppression de compte (désactivation immédiate, effacement sous 30 jours).
-- ---------------------------------------------------------------------------

ALTER TABLE users
    MODIFY email          VARCHAR(191) NULL COMMENT 'Peut manquer (Apple sans e-mail) ; jamais renvoyé par l''API',
    MODIFY password_hash  VARCHAR(255) NULL COMMENT 'Ancienne connexion par mot de passe (back-office, tests)',
    MODIFY status         ENUM('active','disabled','deleted') NOT NULL DEFAULT 'active',
    ADD COLUMN display_name          VARCHAR(40)  NULL COMMENT 'Nom affiché, unique sans tenir compte des accents ni de la casse' AFTER username,
    ADD COLUMN avatar_kind           ENUM('symbol','photo') NOT NULL DEFAULT 'symbol' AFTER bio,
    ADD COLUMN avatar_value          VARCHAR(500) NOT NULL DEFAULT 'person.crop.circle.fill' COMMENT 'Symbole SF Symbols ou URL de la photo' AFTER avatar_kind,
    ADD COLUMN notifications_enabled TINYINT(1)   NOT NULL DEFAULT 1 AFTER avatar_value,
    ADD COLUMN role                  ENUM('user','partner','moderator','admin') NOT NULL DEFAULT 'user' AFTER notifications_enabled,
    ADD COLUMN apple_sub             VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER role,
    ADD COLUMN google_sub            VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER apple_sub,
    ADD COLUMN apple_refresh_encrypted VARCHAR(1024) CHARACTER SET ascii NULL COMMENT 'Jeton Apple chiffré : révocation à la suppression du compte' AFTER google_sub,
    ADD COLUMN deleted_at            DATETIME     NULL COMMENT 'Demande de suppression : données effacées 30 jours après' AFTER updated_at,
    ADD UNIQUE KEY uq_users_display_name (display_name),
    ADD UNIQUE KEY uq_users_apple (apple_sub),
    ADD UNIQUE KEY uq_users_google (google_sub),
    ADD KEY idx_users_deleted (status, deleted_at);

UPDATE users SET display_name = username WHERE display_name IS NULL;

-- Jetons de rafraîchissement : famille (la réutilisation d'un ancien jeton révoque toute la famille)
ALTER TABLE refresh_tokens
    ADD COLUMN family_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER device_id,
    ADD KEY idx_refresh_family (family_id);

-- Connexion de secours : code à 6 chiffres envoyé par e-mail, valable 10 minutes
CREATE TABLE IF NOT EXISTS auth_email_codes (
    email       VARCHAR(191) NOT NULL,
    code_hash   CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME     NOT NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (email),
    KEY idx_auth_email_codes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
