-- ---------------------------------------------------------------------------
-- Contenu réel envoyé par le Claude de l'app (canal agents, 31/10/2026) : identifiant de l'app sur les lieux et les événements,
-- fiches détaillées des incontournables.
-- ---------------------------------------------------------------------------
ALTER TABLE places
    ADD COLUMN app_id   VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Identifiant de l''app (spot-<app_id> dans les favoris), renvoyé comme id' AFTER uid,
    ADD COLUMN details  LONGTEXT NULL COMMENT 'JSON : détails de l''app (types nature, résumé, infos, recherche Apple Plans)' AFTER nature,
    ADD COLUMN guide    LONGTEXT NULL COMMENT 'JSON : fiche détaillée de l''app (PlaceGuide : intro, faits, temps forts, horaires, infos…)' AFTER details,
    ADD UNIQUE KEY uq_places_app_id (app_id);
