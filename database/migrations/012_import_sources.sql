-- ---------------------------------------------------------------------------
-- Sources d'import fournies : offres France Travail (contact = lien vers l'offre d'origine)
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO content_sources (slug, name, kind, website_url, attribution, contact_mode, auto_publish, is_active, created_at)
VALUES ('france-travail', 'France Travail', 'import', 'https://www.francetravail.fr',
        'Offre diffusée par France Travail', 'external', 1, 1, UTC_TIMESTAMP());

-- Imports : retrouver vite le dernier passage d'une source (planification import:all)
ALTER TABLE import_runs ADD KEY idx_import_runs_last (source_id, id);
