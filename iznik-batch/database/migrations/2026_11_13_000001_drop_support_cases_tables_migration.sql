-- Idempotent production SQL for 2026_11_13_000001_drop_support_cases_tables.php
--
-- Drops the two support case tables with whatever they hold. Both are small (a few days of
-- referrals). The drop takes a brief metadata lock on the tables their foreign keys point at
-- (users, chat_rooms, support_ai_runs); lock_wait_timeout makes that fail fast rather than queue.
-- Run once on production AFTER the Go API, batch and ModTools code that no longer use them is
-- deployed. Safe to re-run.
SET SESSION lock_wait_timeout = 5;

DROP TABLE IF EXISTS `support_case_messages`;
DROP TABLE IF EXISTS `support_cases`;
