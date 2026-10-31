-- ---------------------------------------------------------------------------
-- Mini-jeux du Profil (canal agents, demande iOS 32676324) : une partie par jour, par jeu et par compte.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_scores (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    game_id    VARCHAR(30) CHARACTER SET ascii NOT NULL,
    day        DATE NOT NULL COMMENT 'Jour de la partie, heure de Paris',
    score      SMALLINT UNSIGNED NOT NULL,
    played_at  DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_scores_day (user_id, game_id, day),
    KEY idx_game_scores_board (game_id, day, score),
    CONSTRAINT fk_game_scores_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
