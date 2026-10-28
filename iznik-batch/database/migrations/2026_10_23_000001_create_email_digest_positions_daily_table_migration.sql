-- Idempotent production SQL for 2026_10_23_000001_create_email_digest_positions_daily_table.php
-- Run once on production BEFORE deploying the batch code that writes it (email:digest-positions-rollup)
-- and the Go API that reads it (/modtools/email/stats/digestpositions). One new, empty table.
-- CREATE TABLE IF NOT EXISTS takes no lock on any existing table, and is safe to run twice. If the code
-- is live before this is run, the rollup fails with a missing table, and the API reads the table as
-- empty and falls back to computing at most three days live, so the chart is short rather than broken.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `email_digest_positions_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `day` date NOT NULL,
  `email_type` varchar(64) NOT NULL,
  `position` smallint unsigned NOT NULL,
  `shown` int unsigned NOT NULL DEFAULT '0',
  `emails_clicked` int unsigned NOT NULL DEFAULT '0',
  `clicks` int unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_digest_positions_daily_key` (`day`,`email_type`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
