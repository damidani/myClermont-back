-- Agrégateurs d'offres d'emploi : le bouton « Postuler » renvoie vers l'offre d'origine (contact externe)
INSERT IGNORE INTO content_sources (slug, name, kind, website_url, attribution, contact_mode, auto_publish, is_active, created_at) VALUES
    ('adzuna', 'Adzuna', 'import', 'https://www.adzuna.fr', 'Offre via Adzuna', 'external', 1, 1, UTC_TIMESTAMP()),
    ('jooble', 'Jooble', 'import', 'https://fr.jooble.org', 'Offre via Jooble', 'external', 1, 1, UTC_TIMESTAMP());
