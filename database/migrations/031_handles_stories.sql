-- ---------------------------------------------------------------------------
-- Pseudos publics (@handle) et mentions (demandes 1a584b24, 8417181e), stories des établissements (b5b4c116).
-- Les pseudos des comptes existants sont calculés par `bin/console migrate` (Handles::backfill), en PHP (accents).
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN handle            VARCHAR(30) CHARACTER SET ascii COLLATE ascii_general_ci NULL COMMENT 'Pseudo public, minuscules [a-z0-9._-]' AFTER username,
    ADD COLUMN handle_changed_at DATETIME NULL COMMENT 'Dernier changement choisi par l''utilisateur (NULL = pseudo attribué automatiquement)' AFTER handle,
    ADD UNIQUE KEY uq_users_handle (handle);

-- Ancien pseudo réservé 30 jours après un changement (anciennes mentions)
CREATE TABLE user_handle_history (
    handle      VARCHAR(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    released_at DATETIME NOT NULL,
    KEY idx_handle_history_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mentions repérées dans les posts et commentaires (le texte reste tel quel)
CREATE TABLE community_mentions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id     BIGINT UNSIGNED NOT NULL,
    comment_id  BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NOT NULL COMMENT 'Personne mentionnée',
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uq_mention (post_id, comment_id, user_id),
    KEY idx_mentions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stories des établissements (24 h)
CREATE TABLE pro_stories (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_id   BIGINT UNSIGNED NOT NULL,
    place_id      BIGINT UNSIGNED NULL,
    media_kind    ENUM('image','video') NOT NULL,
    media_url     VARCHAR(500) NOT NULL,
    duration_sec  SMALLINT UNSIGNED NOT NULL DEFAULT 6,
    caption       VARCHAR(90) NOT NULL DEFAULT '',
    caption_style ENUM('bandeau','plein','aucun') NOT NULL DEFAULT 'bandeau',
    cta           LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (cta IS NULL OR json_valid(cta)),
    starts_at     DATETIME NOT NULL,
    expires_at    DATETIME NOT NULL,
    removed_at    DATETIME NULL,
    removed_by    ENUM('pro','moderation') NULL,
    reviewed_at   DATETIME NULL COMMENT 'Vue par la modération (file « Stories »)',
    views         INT UNSIGNED NOT NULL DEFAULT 0,
    cta_taps      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    UNIQUE KEY uq_pro_stories_uid (uid),
    KEY idx_pro_stories_live (removed_at, starts_at, expires_at),
    KEY idx_pro_stories_business (business_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une vue / un tap comptés une fois par appareil
CREATE TABLE pro_story_hits (
    story_id   BIGINT UNSIGNED NOT NULL,
    device_id  BIGINT UNSIGNED NOT NULL,
    type       ENUM('view','cta') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (story_id, device_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE uploads MODIFY purpose ENUM('cv','post','classified','avatar','place','deal','event','housing','story') NOT NULL;
