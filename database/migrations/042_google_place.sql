-- Avis Google des lieux (303b2796) : seul l'identifiant Google du lieu est gardé (autorisé sans limite par Google) ;
-- la note et les avis restent en cache 24 h, jamais en base.
ALTER TABLE places
    ADD COLUMN google_place_id   VARCHAR(255) NULL COMMENT 'Place ID Google ; vide = pas de correspondance' AFTER external_url,
    ADD COLUMN google_checked_at DATETIME NULL COMMENT 'Dernière recherche de correspondance' AFTER google_place_id;
