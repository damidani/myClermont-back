-- ---------------------------------------------------------------------------
-- Canal entre assistants (Claude de l'app iOS / Android, Claude du back-end) : demandes, réponses, notes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agent_messages (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    thread_uid  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Fil : uid du premier message',
    sender      VARCHAR(30) CHARACTER SET ascii NOT NULL COMMENT 'ios, android, backend, web…',
    recipient   VARCHAR(30) CHARACTER SET ascii NOT NULL,
    kind        ENUM('request','reply','note','bug') NOT NULL DEFAULT 'request',
    title       VARCHAR(200) NOT NULL,
    body        MEDIUMTEXT NOT NULL COMMENT 'Markdown : besoin, routes, JSON attendu…',
    status      ENUM('open','in_progress','done','rejected') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_messages_uid (uid),
    KEY idx_agent_messages_inbox (recipient, status, id),
    KEY idx_agent_messages_thread (thread_uid, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
