-- ---------------------------------------------------------------------------
-- Sorties selon la spec v1 : identifiant = slug « titre-date », type fermé (kind), propositions publiques,
-- publication par les partenaires, archivage 90 jours après la fin.
-- ---------------------------------------------------------------------------

ALTER TABLE events
    MODIFY status ENUM('draft','pending','published','rejected','cancelled','archived') NOT NULL DEFAULT 'draft',
    ADD COLUMN kind           ENUM('soiree','concert','spectacle','expo','famille','sport','marche','festival') NULL AFTER category_id,
    ADD COLUMN summary        VARCHAR(500) NULL COMMENT 'Texte court de la carte' AFTER title,
    ADD COLUMN schedule_label VARCHAR(160) NULL COMMENT '« Tous les samedis, 8 h – 13 h » : prime sur l''affichage calculé' AFTER ends_at,
    ADD COLUMN all_day        TINYINT(1)   NOT NULL DEFAULT 0 AFTER schedule_label,
    ADD COLUMN age_limit      VARCHAR(60)  NULL COMMENT '« 25 ans et plus »' AFTER price_min_cents,
    ADD COLUMN link_url       VARCHAR(500) NULL COMMENT 'Lien de l''événement (site, billetterie, Instagram)' AFTER ticket_url,
    ADD COLUMN place_id       BIGINT UNSIGNED NULL AFTER neighborhood_id,
    ADD COLUMN author_user_id BIGINT UNSIGNED NULL COMMENT 'Auteur (proposition publique ou partenaire)' AFTER place_id,
    ADD COLUMN deleted_at     DATETIME NULL AFTER updated_at,
    ADD KEY idx_events_spec (status, deleted_at, ends_at, starts_at),
    ADD CONSTRAINT fk_events_place  FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_events_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL;

-- Type déduit de la catégorie pour les événements existants
UPDATE events e JOIN event_categories c ON c.id = e.category_id SET e.kind = CASE c.slug
    WHEN 'musique' THEN 'concert' WHEN 'culture' THEN 'expo' WHEN 'festival' THEN 'festival' WHEN 'famille' THEN 'famille'
    WHEN 'sport' THEN 'sport' WHEN 'marche' THEN 'marche' WHEN 'conference' THEN 'spectacle' WHEN 'nature' THEN 'famille'
    ELSE 'soiree' END
WHERE e.kind IS NULL;

ALTER TABLE event_categories
    ADD COLUMN kind ENUM('soiree','concert','spectacle','expo','famille','sport','marche','festival') NULL COMMENT 'Catégorie utilisée pour ce type' AFTER slug;
UPDATE event_categories SET kind = CASE slug
    WHEN 'musique' THEN 'concert' WHEN 'culture' THEN 'expo' WHEN 'festival' THEN 'festival' WHEN 'famille' THEN 'famille'
    WHEN 'sport' THEN 'sport' WHEN 'marche' THEN 'marche' WHEN 'conference' THEN 'spectacle' WHEN 'gastronomie' THEN 'soiree' ELSE NULL END;
INSERT IGNORE INTO event_categories (slug, kind, name, icon, color, sort_order) VALUES
    ('soiree', 'soiree', 'Soirée', 'party', '#B5427A', 5);
