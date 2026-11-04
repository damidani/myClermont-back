-- Formule Partenaire offerte depuis l'administration (demande du 04/11)
ALTER TABLE pro_businesses
    ADD COLUMN plan_until      DATETIME NULL COMMENT 'Fin de la formule offerte (NULL = sans limite ou payante)' AFTER plan_since,
    ADD COLUMN plan_offered_by VARCHAR(120) NULL COMMENT 'Membre de l''équipe qui a offert la formule' AFTER plan_until,
    ADD COLUMN plan_note       VARCHAR(300) NULL AFTER plan_offered_by;
