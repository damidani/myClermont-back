-- ---------------------------------------------------------------------------
-- Brief app iOS §5 : un post ou un commentaire retenu par le filtre attend un modérateur (hors des listes publiques).
-- ---------------------------------------------------------------------------
ALTER TABLE community_posts MODIFY status ENUM('visible','pending','hidden','deleted') NOT NULL DEFAULT 'visible';
ALTER TABLE community_comments MODIFY status ENUM('visible','pending','deleted') NOT NULL DEFAULT 'visible';
