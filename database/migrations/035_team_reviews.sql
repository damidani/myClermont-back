-- ---------------------------------------------------------------------------
-- « L'avis de la team MyClermont » sur la fiche d'un lieu (demandes iOS 4a9dc3a3, 2399ca9a), rédigé par l'équipe
-- depuis l'espace /admin du dashboard ; le commerçant le voit en lecture seule. Historique gardé par clé de lieu.
-- ---------------------------------------------------------------------------
CREATE TABLE place_team_reviews (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    place_id      BIGINT UNSIGNED NOT NULL,
    place_key     VARCHAR(255) NOT NULL COMMENT 'Clé de l''app : spot-…, place-…, event-…',
    title         VARCHAR(80) NOT NULL DEFAULT '',
    text          TEXT NOT NULL,
    rating        DECIMAL(2,1) NULL,
    verdict       ENUM('Coup de cœur','Recommandé','Bon plan','Incontournable') NULL,
    pros          LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '[]' CHECK (json_valid(pros)),
    cons          LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '[]' CHECK (json_valid(cons)),
    author        VARCHAR(60) NOT NULL DEFAULT '',
    visited_at    DATETIME NULL,
    status        ENUM('brouillon','publie') NOT NULL DEFAULT 'brouillon',
    published_at  DATETIME NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    updated_by    VARCHAR(120) NOT NULL DEFAULT '',
    UNIQUE KEY uq_team_reviews_place (place_id),
    KEY idx_team_reviews_status (status, updated_at),
    CONSTRAINT fk_team_reviews_place FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE place_team_review_audit (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    place_key   VARCHAR(255) NOT NULL,
    actor_kind  ENUM('admin','merchant','system') NOT NULL DEFAULT 'admin',
    actor_name  VARCHAR(120) NOT NULL,
    action      ENUM('redige','modifie','publie','modifie_en_ligne','retire','supprime') NOT NULL,
    summary     VARCHAR(300) NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_team_review_audit_key (place_key(120), id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
