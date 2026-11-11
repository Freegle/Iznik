-- Idempotent production SQL for 2026_10_21_000001_create_ux_flow_signal_daily_table.php
-- Run once on production BEFORE deploying the code that writes it (ux:rollup-daily) or reads it (the
-- "then finished" figures in the ModTools SysAdmin UX tab, /modtools/ux/funnels and /modtools/ux/fields).
-- One new, empty table. CREATE TABLE IF NOT EXISTS takes no lock on any existing table, and is safe to
-- run twice. If the code is live before this is run, the rollup skips the table with a warning and the
-- read leaves the figures out, so the tab shows nothing for "then finished" rather than failing.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_flow_signal_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `flow` varchar(32) NOT NULL,
  `step` varchar(64) NOT NULL,
  `field` varchar(64) NOT NULL DEFAULT '',
  `signal` varchar(16) NOT NULL,
  `device_class` varchar(16) NOT NULL DEFAULT '',
  `logged_in` tinyint(1) NOT NULL DEFAULT '0',
  `is_app` tinyint(1) NOT NULL DEFAULT '0',
  `variant` varchar(64) NOT NULL DEFAULT '',
  `people` int unsigned NOT NULL DEFAULT '0',
  `finished` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_flow_signal_daily_key` (`date`,`flow`,`step`,`field`,`signal`,`device_class`,`logged_in`,`is_app`,`variant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
