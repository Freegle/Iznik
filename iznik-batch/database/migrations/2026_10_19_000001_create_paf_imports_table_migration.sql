-- Idempotent production SQL for 2026_10_19_000001_create_paf_imports_table.php
-- One new small table. CREATE TABLE IF NOT EXISTS takes no lock on any existing table, and is
-- safe to run twice. No existing table is altered.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `paf_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `edition` varchar(8) DEFAULT NULL,
  `unlock_key` varchar(64) DEFAULT NULL,
  `progress` tinyint unsigned NOT NULL DEFAULT '0',
  `detail` varchar(255) DEFAULT NULL,
  `lines_in_release` int unsigned DEFAULT NULL,
  `lines_to_apply` int unsigned DEFAULT NULL,
  `lines_applied` int unsigned NOT NULL DEFAULT '0',
  `inserted` int unsigned NOT NULL DEFAULT '0',
  `updated` int unsigned NOT NULL DEFAULT '0',
  `skipped` int unsigned NOT NULL DEFAULT '0',
  `error` text,
  `requested_by` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `paf_imports_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
