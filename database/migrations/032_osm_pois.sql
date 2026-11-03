-- ---------------------------------------------------------------------------
-- Commerces et services de la métropole issus d'OpenStreetMap (demandes iOS 54d34637, afe694b8) :
-- Clermont IA y cherche « le coiffeur le plus proche », « une pharmacie »… Rempli par `bin/console osm:import` (chaque semaine).
-- Données © les contributeurs d'OpenStreetMap, licence ODbL.
-- ---------------------------------------------------------------------------
CREATE TABLE osm_pois (
    id            VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY COMMENT 'node-123, way-456…',
    kind          VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Clé de App\\Modules\\Assistant\\Nearby::KINDS',
    name          VARCHAR(160) NOT NULL,
    latitude      DECIMAL(9,6) NOT NULL,
    longitude     DECIMAL(9,6) NOT NULL,
    address       VARCHAR(255) NOT NULL DEFAULT '',
    phone         VARCHAR(40) NULL,
    website       VARCHAR(255) NULL,
    opening_hours VARCHAR(255) NULL,
    cuisine       VARCHAR(120) NULL,
    updated_at    DATETIME NOT NULL,
    KEY idx_osm_kind_geo (kind, latitude, longitude),
    KEY idx_osm_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
