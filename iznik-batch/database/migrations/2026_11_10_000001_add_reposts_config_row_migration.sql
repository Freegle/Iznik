-- Idempotent production SQL for 2026_11_10_000001_add_reposts_config_row.php
--
-- One row in `config` (a few dozen rows): the repost delays and count used for every community.
-- `key` is unique, so INSERT IGNORE inserts it only when absent and keeps a value set by hand.
-- Run once on production BEFORE deploying the batch code, the Go API and the frontend that read
-- it (they fall back to the same values and log a warning when the row is missing). Safe to re-run.
SET SESSION lock_wait_timeout = 5;

INSERT IGNORE INTO `config` (`key`, `value`)
VALUES ('reposts', '{"offer":3,"wanted":7,"max":3,"chaseups":5}');
