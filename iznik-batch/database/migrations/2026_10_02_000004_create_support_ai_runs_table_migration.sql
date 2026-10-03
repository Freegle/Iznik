-- Production idempotent SQL for 2026_10_02_000004_create_support_ai_runs_table.php
--
-- One row per question put to the AI Support Helper, with the volunteer's thumbs up/down.
-- A new, empty table, so no lock on anything existing beyond the brief metadata lock the
-- foreign keys take on users; lock_wait_timeout makes that fail fast rather than queue.
-- Run once on production BEFORE deploying the iznik-server-go and claude-agent-sdk code
-- that uses it. CREATE TABLE IF NOT EXISTS, so safe to re-run.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `support_ai_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `modid` bigint unsigned DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `sessionid` varchar(64) DEFAULT NULL,
  `query` mediumtext NOT NULL,
  `analysis` mediumtext,
  `transcript` longtext,
  `status` enum('Success','Error') NOT NULL DEFAULT 'Success',
  `error` text,
  `driver` varchar(16) DEFAULT NULL,
  `model` varchar(64) DEFAULT NULL,
  `input_tokens` int unsigned NOT NULL DEFAULT 0,
  `output_tokens` int unsigned NOT NULL DEFAULT 0,
  `cache_creation_tokens` int unsigned NOT NULL DEFAULT 0,
  `cache_read_tokens` int unsigned NOT NULL DEFAULT 0,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `cost_usd` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `quota_5h_before` decimal(5,2) DEFAULT NULL,
  `quota_5h_after` decimal(5,2) DEFAULT NULL,
  `quota_7d_before` decimal(5,2) DEFAULT NULL,
  `quota_7d_after` decimal(5,2) DEFAULT NULL,
  `rating` tinyint DEFAULT NULL,
  `rating_comment` text,
  `ratedby` bigint unsigned DEFAULT NULL,
  `rated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `support_ai_runs_created_at_index` (`created_at`),
  KEY `support_ai_runs_rating_index` (`rating`),
  KEY `support_ai_runs_sessionid_index` (`sessionid`),
  KEY `support_ai_runs_modid_foreign` (`modid`),
  KEY `support_ai_runs_userid_foreign` (`userid`),
  KEY `support_ai_runs_ratedby_foreign` (`ratedby`),
  CONSTRAINT `support_ai_runs_modid_foreign` FOREIGN KEY (`modid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_ai_runs_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_ai_runs_ratedby_foreign` FOREIGN KEY (`ratedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
