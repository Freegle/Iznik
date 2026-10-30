-- Idempotent production SQL for 2026_10_30_000001_create_domain_hosting_table.php
-- Run once on production BEFORE deploying the batch (hosting:scan-domains writes it) and the Go API
-- (GET /user/hostingad reads it). One new, empty table keyed by web domain, holding nothing about any
-- member. CREATE TABLE IF NOT EXISTS takes no lock on any existing table and is safe to run twice.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `domain_hosting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(191) NOT NULL,
  `wordpress` tinyint(1) NOT NULL DEFAULT '0',
  `krystal` tinyint(1) NOT NULL DEFAULT '0',
  `nameserver` varchar(191) DEFAULT NULL,
  `server` varchar(64) DEFAULT NULL,
  `status` smallint unsigned DEFAULT NULL,
  `checked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_hosting_domain` (`domain`),
  KEY `domain_hosting_checked` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
