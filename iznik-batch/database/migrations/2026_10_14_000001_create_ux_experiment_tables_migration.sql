-- Idempotent production SQL for 2026_10_14_000001_create_ux_experiment_tables.php
-- Run once on production BEFORE deploying the code that writes these tables (ux:experiments-tick,
-- ux:experiment) or reads them (the /modtools/ux/experiments routes).
-- Two new small tables. CREATE TABLE IF NOT EXISTS takes no lock on any existing table, and is safe to run twice.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_experiments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow` varchar(32) NOT NULL,
  `name` varchar(64) NOT NULL,
  `title` varchar(160) NOT NULL,
  `hypothesis` text NOT NULL,
  `change_description` text NOT NULL,
  `expected` text NOT NULL,
  `arms` json NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `started_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decision` text,
  `result` json DEFAULT NULL,
  `pr_url` varchar(255) DEFAULT NULL,
  `cleanup_pr_url` varchar(255) DEFAULT NULL,
  `design` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_experiments_name` (`name`),
  KEY `ux_experiments_flow_status` (`flow`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_experiment_reads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `experiment_id` bigint unsigned NOT NULL,
  `read_at` timestamp NOT NULL,
  `hours` decimal(6,2) NOT NULL,
  `metrics` json NOT NULL,
  `summary` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_experiment_reads_lookup` (`experiment_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
