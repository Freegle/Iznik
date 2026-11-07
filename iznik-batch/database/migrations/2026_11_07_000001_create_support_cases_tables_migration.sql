-- Idempotent production SQL for 2026_11_07_000001_create_support_cases_tables.php
--
-- Two new, empty tables (support_cases, support_case_messages) and one column on support_ai_runs
-- (a few hundred rows) added with ALGORITHM=INSTANT, so metadata-only: no rebuild and no long lock.
-- The foreign keys take a brief metadata lock on users, chat_rooms and support_ai_runs;
-- lock_wait_timeout makes that fail fast rather than queue. Run once on production BEFORE deploying
-- the Go API, batch and ModTools code that use them. Safe to re-run.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `support_cases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint unsigned DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `referrerid` bigint unsigned DEFAULT NULL,
  `source` enum('Referral','Mail') NOT NULL DEFAULT 'Referral',
  `reason` text,
  `status` enum('Open','Handled') NOT NULL DEFAULT 'Open',
  `firstpass_status` enum('Pending','Running','Done','Failed','Skipped') NOT NULL DEFAULT 'Pending',
  `firstpass_error` varchar(255) DEFAULT NULL,
  `firstpass_attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `firstpass_runid` bigint unsigned DEFAULT NULL,
  `brief` longtext,
  `handledby` bigint unsigned DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `support_cases_status_id_index` (`status`, `id`),
  KEY `support_cases_chatid_status_index` (`chatid`, `status`),
  KEY `support_cases_referrerid_index` (`referrerid`),
  KEY `support_cases_firstpass_status_created_at_index` (`firstpass_status`, `created_at`),
  KEY `support_cases_userid_foreign` (`userid`),
  KEY `support_cases_firstpass_runid_foreign` (`firstpass_runid`),
  KEY `support_cases_handledby_foreign` (`handledby`),
  CONSTRAINT `support_cases_chatid_foreign` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_cases_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_cases_referrerid_foreign` FOREIGN KEY (`referrerid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_cases_firstpass_runid_foreign` FOREIGN KEY (`firstpass_runid`) REFERENCES `support_ai_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_cases_handledby_foreign` FOREIGN KEY (`handledby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_case_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `caseid` bigint unsigned NOT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `role` enum('Support','Referrer') NOT NULL,
  `body` text NOT NULL,
  `draftused` enum('No','Unchanged','Edited') NOT NULL DEFAULT 'No',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `support_case_messages_caseid_id_index` (`caseid`, `id`),
  KEY `support_case_messages_userid_foreign` (`userid`),
  CONSTRAINT `support_case_messages_caseid_foreign` FOREIGN KEY (`caseid`) REFERENCES `support_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_case_messages_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'support_ai_runs'
      AND column_name = 'kind'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE support_ai_runs ADD COLUMN kind ENUM(''Interactive'',''FirstPass'') NOT NULL DEFAULT ''Interactive'' AFTER modid, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
