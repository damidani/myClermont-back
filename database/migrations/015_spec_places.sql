-- ---------------------------------------------------------------------------
-- Carte selon la spec v1 : lieux au format MapSpot (catégories de l'app), spots nature,
-- photos des lieux, partenaires, gérant (rôle partner).
-- ---------------------------------------------------------------------------

-- Catégorie de l'app (enum fermé de la spec) correspondant à chaque catégorie interne
ALTER TABLE place_categories
    ADD COLUMN app_category ENUM('incontournable','evenement','spot','restaurant','fastFood','cafe','boulangerie','culture','nature','sport','carburant','lieu')
        NOT NULL DEFAULT 'lieu' COMMENT 'Valeur places.category renvoyée à l''app' AFTER slug;

UPDATE place_categories SET app_category = CASE slug
    WHEN 'monuments' THEN 'culture'  WHEN 'restaurants' THEN 'restaurant' WHEN 'cafes' THEN 'cafe'
    WHEN 'patisseries' THEN 'boulangerie' WHEN 'culture' THEN 'culture'   WHEN 'sport' THEN 'sport'
    WHEN 'parcs' THEN 'nature'       WHEN 'volcans' THEN 'nature'          WHEN 'carburant' THEN 'carburant'
    ELSE 'lieu' END;

INSERT IGNORE INTO place_categories (slug, app_category, name, icon, color, sort_order) VALUES
    ('fast-food', 'fastFood', 'Restauration rapide', 'burger', '#E2903A', 25),
    ('spots',     'spot',     'Spots nature',        'binoculars', '#4E8F4A', 85);

ALTER TABLE places
    ADD COLUMN slug           VARCHAR(140) CHARACTER SET ascii NULL AFTER uid,
    ADD COLUMN app_category   ENUM('incontournable','evenement','spot','restaurant','fastFood','cafe','boulangerie','culture','nature','sport','carburant','lieu')
                              NULL COMMENT 'Force la catégorie de l''app (sinon : incontournable si is_must_see, puis celle de la catégorie)' AFTER category_id,
    ADD COLUMN subtitle       VARCHAR(160) NULL COMMENT 'Ligne affichée sous le nom (« Nature · à 1,1 km de Jaude »)' AFTER tagline,
    ADD COLUMN demo_name      VARCHAR(120) NULL COMMENT 'Nom utilisé par l''app avant l''API (clés des favoris locaux, manifeste photos)' AFTER name,
    ADD COLUMN nature         JSON NULL COMMENT 'Spots nature : {kinds[], area, highlight, summary, facts[], propice, warning}' AFTER timeline,
    ADD COLUMN partner_since  DATE NULL AFTER is_partner,
    ADD COLUMN partner_until  DATE NULL COMMENT 'Fin du partenariat : le lieu n''est plus « partenaire » après' AFTER partner_since,
    ADD COLUMN manager_user_id BIGINT UNSIGNED NULL COMMENT 'Compte partner qui gère la fiche' AFTER partner_until,
    ADD COLUMN deleted_at     DATETIME NULL AFTER updated_at,
    ADD UNIQUE KEY uq_places_slug (slug),
    ADD KEY idx_places_updated (status, updated_at),
    ADD CONSTRAINT fk_places_manager FOREIGN KEY (manager_user_id) REFERENCES users (id) ON DELETE SET NULL;

UPDATE places SET demo_name = name WHERE demo_name IS NULL;

CREATE TABLE IF NOT EXISTS place_photos (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    place_id   BIGINT UNSIGNED NOT NULL,
    url        VARCHAR(500) NOT NULL,
    credit     VARCHAR(160) NULL COMMENT '« Photo : Ville de Clermont-Ferrand »',
    source     ENUM('myclermont','partner') NOT NULL DEFAULT 'myclermont',
    width      SMALLINT UNSIGNED NULL,
    height     SMALLINT UNSIGNED NULL,
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_place_photos (place_id, position),
    CONSTRAINT fk_place_photos_place FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La couverture actuelle devient la première photo
INSERT INTO place_photos (place_id, url, source, position, created_at)
SELECT p.id, p.cover_url, 'myclermont', 0, UTC_TIMESTAMP() FROM places p
WHERE p.cover_url IS NOT NULL AND NOT EXISTS (SELECT 1 FROM place_photos ph WHERE ph.place_id = p.id);
