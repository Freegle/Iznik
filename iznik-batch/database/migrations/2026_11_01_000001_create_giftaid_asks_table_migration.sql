-- Idempotent production SQL for 2026_11_01_000001_create_giftaid_asks_table.php
--
-- One new table, giftaid_asks (one row per time a donor was asked for a Gift Aid declaration and
-- has not answered), backfilled with each donor's first chase-up, and one nullable column on
-- giftaid (a few thousand rows) added with ALGORITHM=INSTANT, so no rebuild and no long lock.
-- Run once on production BEFORE deploying the Go API and the batch code that use them.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `giftaid_asks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `askedby` bigint unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `giftaid_asks_userid_timestamp` (`userid`, `timestamp`),
  KEY `giftaid_asks_askedby_foreign` (`askedby`),
  CONSTRAINT `giftaid_asks_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `giftaid_asks_askedby_foreign` FOREIGN KEY (`askedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill each chased-up donor who has no chase-up ask yet (askedby NULL). A second run adds
-- nothing, so run it again straight after the batch deploy to pick up any chase-up mail the old
-- code sent in between, which wrote only users_donations.giftaidchaseup.
INSERT INTO giftaid_asks (userid, askedby, timestamp)
SELECT users_donations.userid, NULL, MIN(users_donations.giftaidchaseup)
FROM users_donations
INNER JOIN users ON users.id = users_donations.userid
WHERE users_donations.giftaidchaseup IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM giftaid_asks a WHERE a.userid = users_donations.userid AND a.askedby IS NULL)
GROUP BY users_donations.userid;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'giftaid'
      AND column_name = 'recordedby'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE giftaid ADD COLUMN recordedby BIGINT UNSIGNED NULL AFTER housenameornumber, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
