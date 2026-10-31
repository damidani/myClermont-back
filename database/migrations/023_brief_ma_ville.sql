-- ---------------------------------------------------------------------------
-- Brief app iOS §11 : icônes en noms SF Symbols valides (liste blanche : src/Support/AppSchemas::ICONS).
-- ---------------------------------------------------------------------------
UPDATE job_categories SET icon = CASE slug
    WHEN 'tech' THEN 'laptopcomputer' WHEN 'communication' THEN 'megaphone.fill' WHEN 'commerce' THEN 'bag.fill'
    WHEN 'hotellerie' THEN 'fork.knife' WHEN 'sante' THEN 'cross.case.fill' WHEN 'education' THEN 'graduationcap.fill'
    WHEN 'industrie' THEN 'gearshape.fill' WHEN 'btp' THEN 'hammer.fill' WHEN 'transport' THEN 'truck.box.fill'
    WHEN 'administratif' THEN 'briefcase.fill' WHEN 'culture' THEN 'building.columns.fill' ELSE 'briefcase.fill' END;

UPDATE service_categories SET icon = CASE slug
    WHEN 'maison-travaux' THEN 'hammer.fill' WHEN 'services-domicile' THEN 'house.fill' WHEN 'informatique' THEN 'desktopcomputer'
    WHEN 'sport-bien-etre' THEN 'figure.run' WHEN 'beaute-sante' THEN 'heart.fill' WHEN 'cours-formation' THEN 'graduationcap.fill'
    WHEN 'animaux' THEN 'pawprint.fill' WHEN 'evenementiel' THEN 'camera.fill' WHEN 'auto-mobilite' THEN 'car.fill'
    ELSE 'wrench.adjustable.fill' END;
