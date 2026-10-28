-- ---------------------------------------------------------------------------
-- Provenance des contenus : myClermont, partenaires, imports automatiques.
-- Commun à tous les modules de contenu (agenda, logement, annonces, lieux...).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS content_sources (
    id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug              VARCHAR(60)  CHARACTER SET ascii NOT NULL,
    name              VARCHAR(100) NOT NULL COMMENT 'Affiché : « leboncoin », « Office de tourisme »...',
    kind              ENUM('internal','partner','import') NOT NULL,
    website_url       VARCHAR(500) NULL,
    logo_url          VARCHAR(500) NULL,
    attribution       VARCHAR(160) NULL COMMENT 'Mention affichée : « Annonce issue de leboncoin »',
    contact_mode      ENUM('internal','external') NOT NULL DEFAULT 'internal'
                      COMMENT 'internal = messagerie myClermont ; external = bouton renvoyant vers external_url',
    auto_publish      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = les contenus arrivent en brouillon, à valider',
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    last_import_at    DATETIME NULL,
    created_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_content_sources_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO content_sources (id, slug, name, kind, contact_mode, auto_publish, created_at)
VALUES (1, 'myclermont', 'myClermont', 'internal', 'internal', 1, UTC_TIMESTAMP());

-- Historique des imports (suivi, alertes, débogage)
CREATE TABLE IF NOT EXISTS import_runs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id    SMALLINT UNSIGNED NOT NULL,
    target       VARCHAR(40) CHARACTER SET ascii NOT NULL COMMENT 'events, housing...',
    started_at   DATETIME NOT NULL,
    finished_at  DATETIME NULL,
    status       ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
    fetched      INT UNSIGNED NOT NULL DEFAULT 0,
    created      INT UNSIGNED NOT NULL DEFAULT 0,
    updated      INT UNSIGNED NOT NULL DEFAULT 0,
    unchanged    INT UNSIGNED NOT NULL DEFAULT 0,
    unpublished  INT UNSIGNED NOT NULL DEFAULT 0,
    errors       INT UNSIGNED NOT NULL DEFAULT 0,
    message      TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_import_runs_source (source_id, started_at),
    CONSTRAINT fk_import_runs_source FOREIGN KEY (source_id) REFERENCES content_sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agenda : provenance de chaque événement
ALTER TABLE events
    ADD COLUMN source_id      SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD COLUMN external_id    VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Identifiant chez la source (dédoublonnage des imports)' AFTER source_id,
    ADD COLUMN external_url   VARCHAR(500) NULL COMMENT 'Page d''origine (bouton « Voir sur … »)' AFTER external_id,
    ADD COLUMN content_hash   CHAR(40) CHARACTER SET ascii NULL COMMENT 'Évite de réécrire un contenu importé inchangé' AFTER external_url,
    ADD COLUMN last_synced_at DATETIME NULL AFTER content_hash,
    ADD UNIQUE KEY uq_events_external (source_id, external_id),
    ADD CONSTRAINT fk_events_source FOREIGN KEY (source_id) REFERENCES content_sources (id);
