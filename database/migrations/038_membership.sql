-- My Clermont+ (9cdabedf) : essai gratuit et ordre des notifications App Store
ALTER TABLE subscriptions
    ADD COLUMN in_trial       TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_renew,
    ADD COLUMN last_signed_at BIGINT UNSIGNED NULL COMMENT 'signedDate (ms) de la dernière notification appliquée' AFTER last_notification;
