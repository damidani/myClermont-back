-- ---------------------------------------------------------------------------
-- Canal entre assistants : comptes rendus de session (kind = report) et dernière connexion de chaque assistant.
-- ---------------------------------------------------------------------------
ALTER TABLE agent_messages MODIFY kind ENUM('request','reply','note','bug','report') NOT NULL DEFAULT 'request';

CREATE TABLE IF NOT EXISTS agent_presence (
    agent        VARCHAR(30) CHARACTER SET ascii NOT NULL,
    last_seen_at DATETIME NOT NULL,
    last_ip      VARCHAR(45) CHARACTER SET ascii NULL,
    app_version  VARCHAR(40) NULL COMMENT 'Version / build sur laquelle l''assistant travaille',
    branch       VARCHAR(120) NULL,
    commit_sha   VARCHAR(64) CHARACTER SET ascii NULL,
    last_report  VARCHAR(200) NULL COMMENT 'Titre du dernier compte rendu',
    PRIMARY KEY (agent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
