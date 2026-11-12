-- Idempotent production SQL for 2026_11_12_000001_create_chat_late_mails_table.php
--
-- A new, empty table: instant, and nothing else is touched. Run once on production BEFORE deploying
-- the iznik-batch code that uses it. Until it exists a late message is simply not mailed (the claim
-- insert fails and is logged for that recipient), which is the behaviour before the change.
SET SESSION lock_wait_timeout = 5;
CREATE TABLE IF NOT EXISTS `chat_late_mails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatmsgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_late_mails_msg_user` (`chatmsgid`,`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
