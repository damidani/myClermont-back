-- ---------------------------------------------------------------------------
-- Validation des inscriptions commerçant par l'équipe MyClermont (demande iOS ca817cb4, priorité 2).
-- Tant que l'établissement n'est pas « verifie », il prépare sa fiche mais ne peut rien publier.
-- Les établissements déjà vérifiés le restent.
-- ---------------------------------------------------------------------------
ALTER TABLE pro_businesses
    MODIFY verification ENUM('a_verifier','en_cours','complement','verifie','refuse') NOT NULL DEFAULT 'a_verifier',
    ADD COLUMN verification_review LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
        COMMENT 'JSON : sirene, claim, request, refusal, history (journal des décisions)' CHECK (verification_review IS NULL OR json_valid(verification_review)) AFTER verification,
    ADD COLUMN verification_submitted_at DATETIME NULL AFTER verification_review,
    ADD COLUMN verification_decided_at DATETIME NULL AFTER verification_submitted_at;
