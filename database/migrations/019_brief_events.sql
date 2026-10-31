-- ---------------------------------------------------------------------------
-- Brief app iOS (30/10/2026) §3 : un marché n'est pas une soirée ; textes jamais NULL.
-- ---------------------------------------------------------------------------
UPDATE events SET kind = 'marche' WHERE (title LIKE 'Marché%' OR title LIKE 'Marche %') AND (kind IS NULL OR kind = 'soiree');
UPDATE events SET address = '' WHERE address IS NULL;
