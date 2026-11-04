-- My Clermont+ offert depuis l'administration (04/11) : gardé à part pour ne pas être écrasé par l'abonnement App Store
ALTER TABLE users
    ADD COLUMN plus_gift_until DATETIME NULL COMMENT 'Fin de My Clermont+ offert (9999-12-31 = sans limite)' AFTER premium_source,
    ADD COLUMN plus_gift_by    VARCHAR(120) NULL AFTER plus_gift_until,
    ADD COLUMN plus_gift_note  VARCHAR(300) NULL AFTER plus_gift_by;
