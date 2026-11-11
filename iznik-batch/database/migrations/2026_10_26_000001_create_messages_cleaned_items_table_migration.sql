-- Idempotent production SQL for 2026_10_26_000001_create_messages_cleaned_items_table.php
-- Run once on production BEFORE deploying the Go API and batch that use it. One new, empty table.
-- CREATE TABLE IF NOT EXISTS is safe to run twice. The foreign key references messages but takes
-- only a brief metadata lock on it, because the new table is empty.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `messages_cleaned_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `item` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `itemkey` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `messages_cleaned_items_msgid` (`msgid`),
  KEY `messages_cleaned_items_itemkey` (`itemkey`),
  CONSTRAINT `messages_cleaned_items_msgid_foreign` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
