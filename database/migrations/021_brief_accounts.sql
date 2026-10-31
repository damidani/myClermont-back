-- ---------------------------------------------------------------------------
-- Brief app iOS §6 : profil du parcours d'inscription (e-mail + mot de passe, Apple, Google), mot de passe oublié.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN first_name         VARCHAR(40)  NULL COMMENT 'Prénom, nom affiché dans la Communauté' AFTER display_name,
    ADD COLUMN phone              VARCHAR(20)  CHARACTER SET ascii NULL COMMENT 'E.164' AFTER first_name,
    ADD COLUMN gender             ENUM('woman','man','nonBinary','genderfluid','agender','transWoman','transMan','selfDescribed','undisclosed') NULL AFTER phone,
    ADD COLUMN gender_detail      VARCHAR(60)  NULL AFTER gender,
    ADD COLUMN pronouns           ENUM('elle','il','iel','elleIl','custom','undisclosed') NULL AFTER gender_detail,
    ADD COLUMN pronouns_detail    VARCHAR(40)  NULL AFTER pronouns,
    ADD COLUMN city               VARCHAR(100) NULL AFTER pronouns_detail,
    ADD COLUMN postal_code        CHAR(5)      CHARACTER SET ascii NULL AFTER city,
    ADD COLUMN city_served        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '21 communes de Clermont Auvergne Métropole' AFTER postal_code,
    ADD COLUMN notify_city_launch TINYINT(1)   NOT NULL DEFAULT 0 AFTER city_served,
    ADD COLUMN marketing_opt_in   TINYINT(1)   NOT NULL DEFAULT 0 AFTER notify_city_launch,
    ADD COLUMN consent_version    VARCHAR(20)  CHARACTER SET ascii NULL COMMENT 'Version des CGU acceptée ; NULL = profil pas encore complété' AFTER marketing_opt_in,
    ADD COLUMN consented_at       DATETIME     NULL AFTER consent_version,
    ADD COLUMN age_confirmed_at   DATETIME     NULL AFTER consented_at;

-- Le nom affiché n'est plus choisi à l'inscription : deux comptes peuvent avoir le même prénom
ALTER TABLE users DROP INDEX uq_users_display_name, ADD KEY idx_users_display_name (display_name);

-- Lien de réinitialisation : 1 h, usage unique
CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
