-- ---------------------------------------------------------------------------
-- Photos des visiteurs (demande iOS ca817cb4) : prises depuis un pin de l'app, validées par le commerçant du lieu
-- (établissement vérifié) ou, à défaut, par l'équipe MyClermont. Rien n'est publié sans validation.
-- ---------------------------------------------------------------------------
CREATE TABLE place_visitor_photos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id        BIGINT UNSIGNED NOT NULL,
    place_key      VARCHAR(255) NOT NULL COMMENT 'Clé du pin dans l''app : spot-…, place-…, event-…',
    place_id       BIGINT UNSIGNED NULL,
    event_id       BIGINT UNSIGNED NULL,
    business_id    BIGINT UNSIGNED NULL COMMENT 'Commerçant qui décide (vérifié) ; NULL = équipe MyClermont',
    pin_name       VARCHAR(160) NOT NULL DEFAULT '',
    url            VARCHAR(500) NOT NULL,
    file_hash      CHAR(64) CHARACTER SET ascii NULL,
    caption        VARCHAR(90) NOT NULL DEFAULT '',
    taken_at       DATETIME NULL,
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    refusal_reason ENUM('floue','hors_sujet','personnes','inapproprie','doublon','autre') NULL,
    refusal_note   VARCHAR(200) NULL,
    in_gallery     TINYINT(1) NOT NULL DEFAULT 0,
    decided_at     DATETIME NULL,
    decided_by     VARCHAR(120) NULL COMMENT 'Nom du membre ou « Équipe MyClermont »',
    created_at     DATETIME NOT NULL,
    UNIQUE KEY uq_visitor_photos_uid (uid),
    KEY idx_visitor_photos_user (user_id, created_at),
    KEY idx_visitor_photos_place (place_key(120), status),
    KEY idx_visitor_photos_business (business_id, status),
    KEY idx_visitor_photos_hash (user_id, file_hash),
    CONSTRAINT fk_visitor_photos_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE uploads MODIFY purpose ENUM('cv','post','classified','avatar','place','deal','event','housing','story','visitor_photo','verification') NOT NULL;

ALTER TABLE pro_notifications MODIFY kind ENUM('avis','message','candidature','devis','code','moderation','systeme','photo') NOT NULL;
