-- Idempotent production SQL for 2026_11_01_000001_create_messages_viewcounts_table.php
-- Run once on production BEFORE deploying the batch (messages:count-views writes it) and the Go API
-- (the dashboard's Popular Posts reads it). One new, empty table. CREATE TABLE IF NOT EXISTS takes no
-- lock on any existing table and is safe to run twice.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `messages_viewcounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `arrival` datetime NOT NULL,
  `views` int unsigned NOT NULL,
  `counted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `messages_viewcounts_msgid` (`msgid`),
  KEY `messages_viewcounts_arrival_views` (`arrival`,`views`,`msgid`),
  KEY `messages_viewcounts_counted_at` (`counted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
