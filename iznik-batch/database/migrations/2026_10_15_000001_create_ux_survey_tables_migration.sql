-- Idempotent production SQL for 2026_10_15_000001_create_ux_survey_tables.php
-- Run once on production BEFORE the batch code is updated: account deletion, the data export and the
-- user dump read ux_prompt_responses. Three new small tables. CREATE TABLE IF NOT EXISTS takes no lock
-- on any existing table, and is safe to run twice. No existing table is altered.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_prompts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `flow` varchar(32) DEFAULT NULL,
  `question` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `options` json NOT NULL,
  `images` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `max_responses` int unsigned NOT NULL DEFAULT '200',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_prompts_status_kind` (`status`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_problems` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow` varchar(32) DEFAULT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'problem',
  `group_name` varchar(40) DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `description` text,
  `prompt_id` bigint unsigned DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `mentions` int unsigned NOT NULL DEFAULT '0',
  `weight` decimal(6,3) DEFAULT NULL,
  `evidence` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_problems_flow_status` (`flow`,`status`),
  KEY `ux_problems_prompt` (`prompt_id`),
  KEY `ux_problems_group` (`kind`,`group_name`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_prompt_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prompt_id` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `answer` json NOT NULL,
  `comment` text,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_prompt_responses_once` (`prompt_id`,`userid`),
  KEY `ux_prompt_responses_user_time` (`userid`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
