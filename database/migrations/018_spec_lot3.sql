-- ---------------------------------------------------------------------------
-- Spec v1 lot 3 : Clermont IA (quotas, journal 30 jours), abonnement My Clermont+ (StoreKit 2).
-- ---------------------------------------------------------------------------

-- Compteur par compte, ou par appareil sans compte
CREATE TABLE IF NOT EXISTS ai_usage (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    day         DATE NOT NULL,
    scope_key   VARCHAR(30) CHARACTER SET ascii NOT NULL COMMENT 'u:<user id> ou d:<device id>',
    user_id     BIGINT UNSIGNED NULL,
    device_id   BIGINT UNSIGNED NULL,
    questions   INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_in   INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_out  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_usage (day, scope_key),
    KEY idx_ai_usage_scope (scope_key),
    KEY idx_ai_usage_user (user_id),
    KEY idx_ai_usage_device (device_id),
    CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_usage_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Questions gardées 30 jours pour le débogage, jamais la position, jamais réutilisées pour entraîner un modèle
CREATE TABLE IF NOT EXISTS ai_questions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id CHAR(36) CHARACTER SET ascii NOT NULL,
    user_id         BIGINT UNSIGNED NULL,
    device_id       BIGINT UNSIGNED NULL,
    screen          ENUM('chat','map') NOT NULL DEFAULT 'chat',
    question        VARCHAR(500) NOT NULL,
    answer          VARCHAR(2000) NULL,
    tools_used      VARCHAR(255) CHARACTER SET ascii NULL,
    tokens_in       INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_out      INT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('answered','cached','refused','failed') NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ai_questions_created (created_at),
    CONSTRAINT fk_ai_questions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_questions_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Abonnements (App Store aujourd'hui, Google Play ensuite)
CREATE TABLE IF NOT EXISTS subscriptions (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                  BIGINT UNSIGNED NULL,
    store                    ENUM('apple','google') NOT NULL DEFAULT 'apple',
    original_transaction_id  VARCHAR(64) CHARACTER SET ascii NOT NULL,
    product_id               VARCHAR(120) CHARACTER SET ascii NOT NULL,
    status                   ENUM('active','grace','expired','revoked','refunded') NOT NULL,
    auto_renew               TINYINT(1) NOT NULL DEFAULT 1,
    expires_at               DATETIME NULL,
    environment              ENUM('Production','Sandbox') NOT NULL DEFAULT 'Production',
    last_notification        VARCHAR(40) NULL,
    created_at               DATETIME NOT NULL,
    updated_at               DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscriptions_original (store, original_transaction_id),
    KEY idx_subscriptions_user (user_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
