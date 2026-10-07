-- Idempotent production SQL for 2026_10_05_000001_create_rippling_blocked_table.php
--
-- One row per post that must never ripple out again (a home community moved it back to
-- pending). A new, empty table: TOI-safe, instant. Run once on production BEFORE deploying
-- the iznik-server-go and iznik-batch code that uses it.
SET SESSION lock_wait_timeout = 5;
CREATE TABLE IF NOT EXISTS `rippling_blocked` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `byuser` bigint unsigned DEFAULT NULL,
  `reason` varchar(80) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rippling_blocked_msgid_unique` (`msgid`),
  KEY `rippling_blocked_byuser_foreign` (`byuser`),
  CONSTRAINT `rippling_blocked_msgid_foreign` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rippling_blocked_byuser_foreign` FOREIGN KEY (`byuser`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
