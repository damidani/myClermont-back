-- ---------------------------------------------------------------------------
-- Brief app iOS §9 : favoris et avis rangés sous la clé texte de l'app (spot-…, place-<nom>, event-…, classified_…, id brut),
-- y compris pour un objet que le back ne connaît pas (lieu issu d'Apple Plans).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_favorites (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    fav_key     VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Clé de l''app, décodée, telle quelle',
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_favorites (user_id, fav_key),
    KEY idx_app_favorites_user (user_id, created_at),
    CONSTRAINT fk_app_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avis sur un lieu inconnu du back : place_id NULL, place_key = clé de l'app
ALTER TABLE place_reviews
    MODIFY place_id BIGINT UNSIGNED NULL,
    ADD COLUMN place_key VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER place_id,
    ADD UNIQUE KEY uq_place_reviews_key_user (place_key, user_id),
    ADD KEY idx_place_reviews_key (place_key, status, id);
