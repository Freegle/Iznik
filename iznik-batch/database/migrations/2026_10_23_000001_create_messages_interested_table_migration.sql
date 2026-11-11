-- Idempotent production SQL for 2026_10_23_000001_create_messages_interested_table.php
-- Run once on production BEFORE deploying the Go API and batch that use it. One new, empty table.
-- CREATE TABLE IF NOT EXISTS is safe to run twice. The foreign keys reference messages and users but
-- take only a brief metadata lock on them, because the new table is empty.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `messages_interested` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `chasedat` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `messages_interested_user_msg` (`userid`,`msgid`),
  KEY `messages_interested_msgid` (`msgid`),
  KEY `messages_interested_chase` (`chasedat`,`added`),
  CONSTRAINT `messages_interested_msgid_foreign` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_interested_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
