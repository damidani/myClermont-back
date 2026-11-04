-- Photo de profil modérée (d262fe7d) : l'avatar envoyé attend la modération avant d'être diffusé (avatar_kind = 'photo')
ALTER TABLE users
    ADD COLUMN avatar_pending_url VARCHAR(500) NULL AFTER avatar_value,
    ADD COLUMN avatar_pending_at DATETIME NULL AFTER avatar_pending_url,
    ADD INDEX idx_users_avatar_pending (avatar_pending_at);
