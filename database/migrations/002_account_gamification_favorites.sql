-- ---------------------------------------------------------------------------
-- Modules Account, Gamification, Favorites, Premium
-- ---------------------------------------------------------------------------

-- Quartiers de Clermont-Ferrand (liste modifiable : ajouter des lignes, ne pas réutiliser un id)
CREATE TABLE IF NOT EXISTS neighborhoods (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(60)  CHARACTER SET ascii NOT NULL,
    name       VARCHAR(100) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_neighborhoods_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO neighborhoods (slug, name, sort_order) VALUES
    ('centre-jaude',        'Centre-ville / Jaude',     10),
    ('montferrand',         'Montferrand',              20),
    ('la-gare',             'La Gare',                  30),
    ('saint-alyre',         'Saint-Alyre',              40),
    ('fontgieve',           'Fontgiève',                50),
    ('les-salins',          'Les Salins',               60),
    ('saint-jacques',       'Saint-Jacques',            70),
    ('les-carmes',          'Les Carmes',               80),
    ('delille',             'Delille',                  90),
    ('chanturgue',          'Chanturgue',              100),
    ('les-vergnes',         'Les Vergnes',             110),
    ('croix-de-neyrat',     'Croix-de-Neyrat',         120),
    ('champratel',          'Champratel',              130),
    ('la-glaciere',         'La Glacière',             140),
    ('les-cezeaux',         'Les Cézeaux',             150),
    ('la-pradelle',         'La Pradelle',             160),
    ('oradou',              'Oradou',                  170),
    ('saint-jean',          'Saint-Jean',              180),
    ('beaulieu',            'Beaulieu',                190),
    ('la-plaine',           'La Plaine',               200),
    ('hors-clermont',       'Hors Clermont-Ferrand',   999);

-- Compte : pseudo, bio, quartier, points, premium, parrainage
ALTER TABLE users
    CHANGE display_name username VARCHAR(30) NOT NULL COMMENT 'Pseudo unique (insensible à la casse)',
    ADD COLUMN bio              VARCHAR(500)      NULL AFTER username,
    ADD COLUMN neighborhood_id  SMALLINT UNSIGNED NULL AFTER bio,
    ADD COLUMN points           INT UNSIGNED      NOT NULL DEFAULT 0 AFTER neighborhood_id,
    ADD COLUMN premium_until    DATETIME          NULL AFTER points,
    ADD COLUMN premium_source   VARCHAR(30)       NULL AFTER premium_until,
    ADD COLUMN referral_code    CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER premium_source,
    ADD COLUMN referred_by      BIGINT UNSIGNED   NULL AFTER referral_code,
    ADD UNIQUE KEY uq_users_username (username),
    ADD UNIQUE KEY uq_users_referral (referral_code),
    ADD KEY idx_users_points (points),
    ADD CONSTRAINT fk_users_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users (id) ON DELETE SET NULL;

-- Gamification : événements déjà comptabilisés (idempotence : un même fait ne rapporte qu'une fois)
CREATE TABLE IF NOT EXISTS user_events (
    user_id    BIGINT UNSIGNED NOT NULL,
    event_key  VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, event_key),
    CONSTRAINT fk_user_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gamification : compteurs par utilisateur (favorites.total, favorites.tag.volcan, referrals...)
CREATE TABLE IF NOT EXISTS user_stats (
    user_id BIGINT UNSIGNED NOT NULL,
    stat    VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    value   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, stat),
    CONSTRAINT fk_user_stats_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gamification : badges obtenus (le catalogue est dans config/gamification.php)
CREATE TABLE IF NOT EXISTS user_badges (
    user_id    BIGINT UNSIGNED NOT NULL,
    badge_code VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    earned_at  DATETIME NOT NULL,
    PRIMARY KEY (user_id, badge_code),
    CONSTRAINT fk_user_badges_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gamification : historique des points (users.points = somme, maintenue à jour)
CREATE TABLE IF NOT EXISTS point_transactions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    amount     INT NOT NULL,
    reason     VARCHAR(100) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_points_user (user_id, id),
    CONSTRAINT fk_points_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Favoris génériques : (type, target_id) — type = annonce, lieu, événement... fourni par chaque module
CREATE TABLE IF NOT EXISTS favorites (
    user_id    BIGINT UNSIGNED NOT NULL,
    type       VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_id  VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, type, target_id),
    KEY idx_favorites_user_date (user_id, created_at),
    KEY idx_favorites_target (type, target_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
