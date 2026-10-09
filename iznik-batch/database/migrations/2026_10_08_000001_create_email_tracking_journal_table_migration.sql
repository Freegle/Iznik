-- Idempotent production SQL for 2026_10_08_000001_create_email_tracking_journal_table.php
--
-- A new, empty, bare append-only table (no FK, no secondary index): instant, and nothing else
-- is touched. Run once on production BEFORE deploying the iznik-server-go and iznik-batch code
-- that uses it. (If the table is missing the Go API falls back to its old direct writes, so a
-- deploy that gets ahead of this loses nothing, it just keeps the old load.)
SET SESSION lock_wait_timeout = 5;
CREATE TABLE IF NOT EXISTS `email_tracking_journal` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ref` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` tinyint unsigned NOT NULL,
  `position` varchar(50) DEFAULT NULL,
  `scroll` tinyint unsigned DEFAULT NULL,
  `loaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
