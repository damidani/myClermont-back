-- ---------------------------------------------------------------------------
-- Espace commerçant MyClermont Pro (commercant.myclermont.app), demandes 1 à 8 du canal agents (31/10/2026).
-- Comptes séparés des comptes de l'app. Un établissement (pro_businesses) gère une fiche (places) et ses contenus :
-- chaque contenu garde ses colonnes lues par l'app, plus business_id, pro_status (statut affiché dans l'espace pro)
-- et pro (JSON : champs propres au formulaire pro, renvoyés tels quels).
-- ---------------------------------------------------------------------------
CREATE TABLE pro_businesses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    trade_name      VARCHAR(120) NOT NULL,
    legal_name      VARCHAR(160) NOT NULL DEFAULT '',
    siret           VARCHAR(14) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
    naf_code        VARCHAR(10) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    naf_label       VARCHAR(200) NULL,
    activity        VARCHAR(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    modules         VARCHAR(200) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '' COMMENT 'Séparés par des virgules',
    goals           VARCHAR(300) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
    plan            ENUM('gratuit','partenaire') NOT NULL DEFAULT 'gratuit',
    plan_since      DATETIME NULL,
    verification    ENUM('a_verifier','en_cours','verifie') NOT NULL DEFAULT 'a_verifier',
    place_id        BIGINT UNSIGNED NULL,
    newsletter      TINYINT(1) NOT NULL DEFAULT 0,
    consent_version VARCHAR(40) NULL,
    consented_at    DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    deleted_at      DATETIME NULL,
    UNIQUE KEY uq_pro_businesses_uid (uid),
    KEY idx_pro_businesses_place (place_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pro_members (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid               CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_id       BIGINT UNSIGNED NOT NULL,
    email             VARCHAR(191) NOT NULL,
    password_hash     VARCHAR(255) NULL COMMENT 'NULL tant que l''invitation n''est pas acceptée',
    first_name        VARCHAR(80) NOT NULL DEFAULT '',
    last_name         VARCHAR(80) NOT NULL DEFAULT '',
    job_title         VARCHAR(80) NOT NULL DEFAULT '',
    phone             VARCHAR(30) NOT NULL DEFAULT '',
    role              ENUM('proprietaire','gerant','equipier') NOT NULL,
    status            ENUM('actif','invite') NOT NULL DEFAULT 'actif',
    invite_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    invite_expires_at DATETIME NULL,
    invited_at        DATETIME NULL,
    notifications     LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(notifications)),
    totp_secret       VARCHAR(255) NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    UNIQUE KEY uq_pro_members_uid (uid),
    UNIQUE KEY uq_pro_members_email (email),
    UNIQUE KEY uq_pro_members_invite (invite_token_hash),
    KEY idx_pro_members_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une session = une connexion (navigateur). Le jeton de rafraîchissement tourne à chaque usage ;
-- un ancien jeton rejoué révoque toute la session.
CREATE TABLE pro_sessions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    member_id     BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    remember      TINYINT(1) NOT NULL DEFAULT 0,
    device        VARCHAR(120) NOT NULL DEFAULT '',
    ip            VARCHAR(45) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
    last_seen_at  DATETIME NOT NULL,
    expires_at    DATETIME NOT NULL,
    revoked_at    DATETIME NULL,
    created_at    DATETIME NOT NULL,
    UNIQUE KEY uq_pro_sessions_uid (uid),
    UNIQUE KEY uq_pro_sessions_token (token_hash),
    KEY idx_pro_sessions_previous (previous_hash),
    KEY idx_pro_sessions_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pro_password_resets (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    member_id  BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_pro_resets_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pro_notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_id BIGINT UNSIGNED NOT NULL,
    kind        ENUM('avis','message','candidature','devis','code','moderation','systeme') NOT NULL,
    title       VARCHAR(160) NOT NULL,
    body        VARCHAR(500) NOT NULL DEFAULT '',
    href        VARCHAR(200) NOT NULL DEFAULT '/',
    read_at     DATETIME NULL,
    created_at  DATETIME NOT NULL,
    UNIQUE KEY uq_pro_notifications_uid (uid),
    KEY idx_pro_notifications_business (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pro_contact_requests (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    member_id   BIGINT UNSIGNED NOT NULL,
    slot        VARCHAR(80) NOT NULL DEFAULT '',
    message     VARCHAR(1000) NOT NULL DEFAULT '',
    handled_at  DATETIME NULL,
    created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pro_invoices (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_id  BIGINT UNSIGNED NOT NULL,
    number       VARCHAR(30) NOT NULL,
    date         DATE NOT NULL,
    label        VARCHAR(160) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    status       ENUM('payee','a_payer') NOT NULL DEFAULT 'a_payer',
    pdf_path     VARCHAR(200) NULL,
    created_at   DATETIME NOT NULL,
    UNIQUE KEY uq_pro_invoices_uid (uid),
    KEY idx_pro_invoices_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messagerie client ↔ commerçant (depuis la fiche, un bon plan, un événement…)
CREATE TABLE pro_threads (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_id   BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NULL COMMENT 'Client de l''app',
    subject       VARCHAR(160) NOT NULL,
    source        ENUM('fiche','bon_plan','evenement','emploi','devis','logement') NOT NULL DEFAULT 'fiche',
    source_id     VARCHAR(120) NULL,
    contact_name  VARCHAR(120) NOT NULL DEFAULT '',
    contact_email VARCHAR(191) NOT NULL DEFAULT '',
    contact_phone VARCHAR(30) NOT NULL DEFAULT '',
    unread_pro    TINYINT(1) NOT NULL DEFAULT 1,
    unread_client TINYINT(1) NOT NULL DEFAULT 0,
    archived      TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at    DATETIME NULL COMMENT 'Supprimé côté commerçant (le client garde son fil)',
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    UNIQUE KEY uq_pro_threads_uid (uid),
    KEY idx_pro_threads_business (business_id, updated_at),
    KEY idx_pro_threads_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pro_thread_messages (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    thread_id  BIGINT UNSIGNED NOT NULL,
    sender     ENUM('client','pro') NOT NULL,
    member_id  BIGINT UNSIGNED NULL,
    body       TEXT NOT NULL,
    created_at DATETIME(3) NOT NULL,
    UNIQUE KEY uq_pro_thread_messages_uid (uid),
    KEY idx_pro_thread_messages_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statistiques de fiche remontées par l'app (POST /places/{id}/events) : un événement par type, appareil, lieu et jour
CREATE TABLE place_stat_hits (
    place_id  BIGINT UNSIGNED NOT NULL,
    day       DATE NOT NULL COMMENT 'Jour à l''heure de Paris',
    type      ENUM('view','directions','call','website') NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (place_id, day, type, device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fiche du lieu gérée depuis l'espace pro
ALTER TABLE places
    ADD COLUMN business_id   BIGINT UNSIGNED NULL COMMENT 'Établissement MyClermont Pro qui gère la fiche' AFTER manager_user_id,
    ADD COLUMN pro_status    ENUM('brouillon','en_revue','publiee') NULL COMMENT 'Statut de la fiche dans l''espace pro' AFTER business_id,
    ADD COLUMN pro           LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'JSON : fiche complète de l''espace pro (Place)' CHECK (json_valid(pro)),
    ADD COLUMN pro_pending   LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'JSON : nom, adresse ou catégorie modifiés, en attente de vérification' CHECK (json_valid(pro_pending)),
    ADD KEY idx_places_business (business_id);

-- Avis : réponse publique du commerçant et signalement
ALTER TABLE place_reviews
    ADD COLUMN reply_text      VARCHAR(1000) NULL,
    ADD COLUMN reply_at        DATETIME NULL,
    ADD COLUMN reported_reason VARCHAR(60) NULL COMMENT 'Signalé par le commerçant : sort de la moyenne jusqu''à la décision',
    ADD COLUMN reported_details VARCHAR(500) NULL,
    ADD COLUMN reported_at     DATETIME NULL;

-- Avis déposés par l'app avec l'identifiant brut du lieu (place.id) : rattachés à la fiche pour que le commerçant les voie
UPDATE IGNORE place_reviews r JOIN places p ON p.app_id = r.place_key SET r.place_id = p.id WHERE r.place_id IS NULL;
UPDATE places p SET
    rating_count = (SELECT COUNT(*) FROM place_reviews r WHERE r.place_id = p.id AND r.status IN ('visible', 'pending')),
    rating_avg   = (SELECT ROUND(AVG(rating), 1) FROM place_reviews r WHERE r.place_id = p.id AND r.status IN ('visible', 'pending'))
WHERE p.id IN (SELECT place_id FROM place_reviews WHERE place_id IS NOT NULL);

-- Bons plans
ALTER TABLE deals
    ADD COLUMN business_id BIGINT UNSIGNED NULL AFTER place_id,
    ADD COLUMN pro_status  ENUM('brouillon','actif','en_pause','termine') NULL AFTER status,
    ADD COLUMN per_person  TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER max_redemptions,
    ADD COLUMN pro         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(pro)),
    ADD KEY idx_deals_business (business_id);
ALTER TABLE deal_redemptions
    MODIFY code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Code présenté en caisse (MC-XXXX-XXX)',
    ADD COLUMN validated_by_member BIGINT UNSIGNED NULL;

-- Événements
ALTER TABLE events
    ADD COLUMN business_id    BIGINT UNSIGNED NULL AFTER author_user_id,
    ADD COLUMN pro_status     ENUM('brouillon','en_revue','publie','refuse') NULL AFTER status,
    ADD COLUMN refusal_reason VARCHAR(300) NULL,
    ADD COLUMN is_flash       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Événement Flash : mis en avant le jour J (Partenaires)',
    ADD COLUMN pro            LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'JSON : événement de l''espace pro (ProEvent)' CHECK (json_valid(pro)),
    ADD COLUMN pro_pending    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'JSON : modification en attente de vérification' CHECK (json_valid(pro_pending)),
    ADD KEY idx_events_business (business_id);

-- Recrutement
ALTER TABLE job_offers
    ADD COLUMN business_id BIGINT UNSIGNED NULL AFTER source_id,
    ADD COLUMN pro_status  ENUM('brouillon','publiee','pourvue','archivee') NULL AFTER status,
    ADD COLUMN pro         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(pro)),
    ADD KEY idx_jobs_business (business_id);
ALTER TABLE job_applications
    ADD COLUMN pro_status ENUM('nouvelle','vue','entretien','retenue','refusee') NOT NULL DEFAULT 'nouvelle';

-- Prestations (devis)
ALTER TABLE service_providers
    ADD COLUMN business_id BIGINT UNSIGNED NULL AFTER owner_user_id,
    ADD COLUMN pro         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(pro)),
    ADD KEY idx_providers_business (business_id);
ALTER TABLE service_quote_requests
    ADD COLUMN pro_status ENUM('nouvelle','en_cours','devis_envoye','gagnee','perdue') NOT NULL DEFAULT 'nouvelle',
    ADD COLUMN note       VARCHAR(2000) NULL COMMENT 'Note interne du commerçant, jamais visible du client',
    ADD COLUMN details    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(details));

-- Logements
ALTER TABLE housing_listings
    ADD COLUMN business_id   BIGINT UNSIGNED NULL AFTER source_id,
    ADD COLUMN pro_status    ENUM('brouillon','publiee','louee','archivee') NULL AFTER status,
    ADD COLUMN climate_class CHAR(1) CHARACTER SET ascii COLLATE ascii_general_ci NULL COMMENT 'GES A à G' AFTER energy_class,
    ADD COLUMN deposit_cents INT UNSIGNED NULL AFTER charges_cents,
    ADD COLUMN fees_label    VARCHAR(160) NULL COMMENT 'Honoraires' AFTER deposit_cents,
    ADD COLUMN pro           LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(pro)),
    ADD KEY idx_housing_business (business_id);
ALTER TABLE housing_contact_requests
    ADD COLUMN pro_status ENUM('nouvelle','traitee') NOT NULL DEFAULT 'nouvelle';

-- Envois de fichiers depuis l'espace pro (session web, sans appareil)
ALTER TABLE uploads
    MODIFY user_id BIGINT UNSIGNED NULL,
    MODIFY device_id BIGINT UNSIGNED NULL,
    MODIFY purpose ENUM('cv','post','classified','avatar','place','deal','event','housing') NOT NULL,
    ADD COLUMN member_id BIGINT UNSIGNED NULL COMMENT 'Envoi depuis l''espace pro' AFTER device_id;
ALTER TABLE media MODIFY device_id BIGINT UNSIGNED NULL;
