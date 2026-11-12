-- Idempotent production SQL for 2026_10_15_000002_create_ux_ideas_table.php
-- Run once on production BEFORE deploying the code that writes or reads ux_ideas (ux:ideas-refresh,
-- ux:ideas-sync, ux:idea, /modtools/ux/ideas). One new small table. CREATE TABLE IF NOT EXISTS takes no
-- lock on any existing table and is safe to run twice.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_ideas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `rationale` text NOT NULL,
  `expected` text NOT NULL,
  `evidence` json DEFAULT NULL,
  `score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `type` varchar(16) NOT NULL DEFAULT 'experiment',
  `source` varchar(16) NOT NULL DEFAULT 'ai',
  `problem_id` bigint unsigned DEFAULT NULL,
  `problem_title` varchar(160) DEFAULT NULL,
  `experiment_id` bigint unsigned DEFAULT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_ideas_flow_status` (`flow`,`status`),
  KEY `ux_ideas_problem` (`problem_id`),
  KEY `ux_ideas_experiment` (`experiment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
