-- Bons plans réservés aux membres My Clermont+ (87e896ae) : distinct de exclusive_plus, qui devient une simple mise en avant.
-- Les offres « exclusives » d'aujourd'hui étaient verrouillées : elles le restent.
ALTER TABLE deals
    ADD COLUMN members_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Code réservé aux membres My Clermont+' AFTER exclusive_plus;
UPDATE deals SET members_only = exclusive_plus;
