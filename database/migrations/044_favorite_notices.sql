-- Notifications des favoris (favorite_story / favorite_deal / favorite_event) : envoyées une fois, quand le contenu devient visible.
-- Le contenu déjà en ligne est marqué comme notifié pour ne pas déclencher une vague de vieilles notifications.
ALTER TABLE pro_stories ADD COLUMN favorites_notified_at DATETIME NULL;
ALTER TABLE deals ADD COLUMN favorites_notified_at DATETIME NULL;
ALTER TABLE events ADD COLUMN favorites_notified_at DATETIME NULL;
UPDATE pro_stories SET favorites_notified_at = UTC_TIMESTAMP();
UPDATE deals SET favorites_notified_at = UTC_TIMESTAMP();
UPDATE events SET favorites_notified_at = UTC_TIMESTAMP();
