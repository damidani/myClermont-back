-- ---------------------------------------------------------------------------
-- Notifications de l'app (demande iOS 4890352c) : boîte /me/notifications, préférences, push APNs par environnement,
-- j'aime sur les commentaires.
-- ---------------------------------------------------------------------------
CREATE TABLE user_notices (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    kind         VARCHAR(30) CHARACTER SET ascii NOT NULL,
    family       VARCHAR(12) CHARACTER SET ascii NULL COMMENT 'photos, community, replies, reminders, favorites, games',
    title        VARCHAR(160) NOT NULL,
    body         VARCHAR(300) NOT NULL DEFAULT '',
    image_url    VARCHAR(500) NULL,
    actor        VARCHAR(30) NULL COMMENT 'Pseudo de l''auteur de l''action, jamais l''e-mail',
    target       LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (target IS NULL OR json_valid(target)),
    group_key    VARCHAR(80) CHARACTER SET ascii NULL COMMENT 'Regroupement (j''aime sur une même publication)',
    group_count  INT UNSIGNED NOT NULL DEFAULT 1,
    read_at      DATETIME NULL,
    pushed_at    DATETIME NULL,
    created_at   DATETIME NOT NULL,
    UNIQUE KEY uq_notices_uid (uid),
    KEY idx_notices_user (user_id, id),
    KEY idx_notices_group (user_id, group_key, read_at),
    CONSTRAINT fk_notices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN notification_prefs LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Préférences de l''app : familles, nuit, fuseau'
        CHECK (notification_prefs IS NULL OR json_valid(notification_prefs)) AFTER notifications_enabled;

ALTER TABLE devices
    ADD COLUMN push_environment ENUM('sandbox','production') NULL COMMENT 'Serveur APNs (sandbox pour les builds Xcode)' AFTER push_token;

ALTER TABLE push_outbox
    ADD COLUMN notice_id BIGINT UNSIGNED NULL COMMENT 'Notification de la boîte (pushed_at renseigné à l''envoi)' AFTER thread_id;

CREATE TABLE community_comment_likes (
    comment_id BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (comment_id, user_id),
    KEY idx_comment_likes_user (user_id),
    CONSTRAINT fk_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES community_comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
