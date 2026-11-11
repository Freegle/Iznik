-- Idempotent production SQL for 2026_10_24_000001_alert_require_reply_and_attachments.php
-- Run once on production BEFORE deploying the Go API and batch that use it. The alerts table is small
-- and the ADD COLUMN is INSTANT. The two new tables are empty, so their foreign keys take only a brief
-- metadata lock on alerts and users.
SET SESSION lock_wait_timeout = 5;

-- Skip this statement if alerts.requirereply already exists.
ALTER TABLE `alerts`
  ADD COLUMN `quoted` mediumtext NULL,
  ADD COLUMN `requirereply` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `cancelled` timestamp NULL DEFAULT NULL,
  ALGORITHM=INSTANT;

CREATE TABLE IF NOT EXISTS `alerts_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alertid` bigint unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `size` int unsigned NOT NULL,
  `data` mediumblob NULL,
  PRIMARY KEY (`id`),
  KEY `alerts_attachments_alertid` (`alertid`),
  CONSTRAINT `alerts_attachments_alertid_foreign` FOREIGN KEY (`alertid`) REFERENCES `alerts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alerts_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alertid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `replied` timestamp NULL DEFAULT NULL,
  `reply` text NULL,
  `snoozed_until` timestamp NULL DEFAULT NULL,
  `snooze_used` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alerts_acknowledgements_alert_user` (`alertid`,`userid`),
  KEY `alerts_acknowledgements_userid` (`userid`),
  CONSTRAINT `alerts_acknowledgements_alertid_foreign` FOREIGN KEY (`alertid`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_acknowledgements_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
