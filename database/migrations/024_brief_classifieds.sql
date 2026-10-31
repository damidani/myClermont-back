-- ---------------------------------------------------------------------------
-- Brief app iOS §12.2 : catégories et états des petites annonces au libellé de l'app.
-- ---------------------------------------------------------------------------
ALTER TABLE classifieds MODIFY item_condition ENUM('new','like_new','very_good','good','fair','for_parts') NOT NULL DEFAULT 'good';

UPDATE classified_categories SET name = CASE slug
    WHEN 'maison-deco' THEN 'Maison & déco' WHEN 'high-tech' THEN 'High-tech' WHEN 'mode' THEN 'Mode & accessoires'
    WHEN 'sport-loisirs' THEN 'Sport & plein air' WHEN 'bricolage' THEN 'Jardin & bricolage' WHEN 'enfants' THEN 'Enfants & bébé'
    WHEN 'livres-media' THEN 'Livres & loisirs' WHEN 'vehicules' THEN 'Vélos & mobilité' WHEN 'dons' THEN 'Dons & gratuit'
    WHEN 'autre' THEN 'Jeux & collections' ELSE name END;
UPDATE classified_categories SET slug = 'jeux-collections' WHERE slug = 'autre'
    AND NOT EXISTS (SELECT 1 FROM (SELECT slug FROM classified_categories) c WHERE c.slug = 'jeux-collections');
INSERT IGNORE INTO classified_categories (slug, name, icon, color, sort_order) VALUES
    ('musique', 'Musique', 'music', '#B5427A', 85);
