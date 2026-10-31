-- ---------------------------------------------------------------------------
-- Canal agents (bug iOS 184d36f6) : icônes des catégories d'annonces en noms SF Symbols.
-- ---------------------------------------------------------------------------
UPDATE classified_categories SET icon = CASE slug
    WHEN 'maison-deco' THEN 'sofa.fill' WHEN 'high-tech' THEN 'laptopcomputer' WHEN 'vehicules' THEN 'bicycle'
    WHEN 'mode' THEN 'bag.fill' WHEN 'sport-loisirs' THEN 'figure.hiking' WHEN 'bricolage' THEN 'hammer.fill'
    WHEN 'livres-media' THEN 'books.vertical.fill' WHEN 'enfants' THEN 'figure.2.and.child.holdinghands' WHEN 'musique' THEN 'music.note'
    WHEN 'jeux-collections' THEN 'gamecontroller.fill' WHEN 'dons' THEN 'gift.fill' ELSE 'tag.fill' END;
