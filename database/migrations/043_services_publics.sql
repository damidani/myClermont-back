-- Services publics de Clermont-Ferrand (Annuaire de l'administration, Service-Public.fr, Licence Ouverte) dans le module Services
INSERT INTO service_categories (slug, name, icon, sort_order) VALUES ('services-publics', 'Services publics', 'building.columns.fill', 5)
    ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon);
INSERT INTO content_sources (slug, name, kind, website_url, attribution, contact_mode, auto_publish, is_active, created_at)
    VALUES ('service-public', 'Service-Public.fr', 'import', 'https://lannuaire.service-public.gouv.fr', 'Source : Service-Public.fr (Licence Ouverte)', 'external', 1, 1, UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE attribution = VALUES(attribution), contact_mode = VALUES(contact_mode);
