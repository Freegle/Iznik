-- Idempotent production SQL for 2026_10_16_000001_create_ux_experiment_exposures_table.php
-- Run once on production BEFORE deploying the code that writes it (server code that takes part in an
-- experiment) or reads it (the hourly experiment read). One new, empty table. CREATE TABLE IF NOT EXISTS
-- takes no lock on any existing table, and is safe to run twice. If the batch is live before this is run,
-- forgetting, merging and exporting a member skip the table, and the hourly job skips its clean-up.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_experiment_exposures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `experiment` varchar(64) NOT NULL,
  `arm` varchar(16) NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `subject_type` varchar(16) NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `stratum` varchar(16) DEFAULT NULL,
  `exposed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `detail` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_exposures_subject` (`experiment`,`subject_type`,`subject_id`,`userid`),
  KEY `ux_exposures_user` (`experiment`,`userid`,`id`),
  KEY `ux_exposures_time` (`experiment`,`exposed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
