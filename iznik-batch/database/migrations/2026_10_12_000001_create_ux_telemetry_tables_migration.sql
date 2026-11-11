-- Idempotent production SQL for 2026_10_12_000001_create_ux_telemetry_tables.php
--
-- UX telemetry rollup tables. Run once on production BEFORE deploying the code that writes them
-- (ux:rollup-daily) or reads them (the ModTools SysAdmin UX tab, /modtools/ux/*).
--
-- All nine are new and small (at most a few thousand rows a day) and nothing else reads them, so
-- CREATE TABLE IF NOT EXISTS takes no lock on any existing table: TOI-safe, and safe to run twice.
-- Nothing here is per member. ux_sample_sessions holds session ids that point back into Loki, and
-- the nightly job deletes them after eight days.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_flow_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `flow` varchar(32) NOT NULL,
  `step` varchar(64) NOT NULL,
  `device_class` varchar(16) NOT NULL DEFAULT '',
  `logged_in` tinyint(1) NOT NULL DEFAULT '0',
  `is_app` tinyint(1) NOT NULL DEFAULT '0',
  `variant` varchar(64) NOT NULL DEFAULT '',
  `entered` int unsigned NOT NULL DEFAULT '0',
  `reentered` int unsigned NOT NULL DEFAULT '0',
  `completed` int unsigned NOT NULL DEFAULT '0',
  `backed` int unsigned NOT NULL DEFAULT '0',
  `abandoned` int unsigned NOT NULL DEFAULT '0',
  `revisits` int unsigned NOT NULL DEFAULT '0',
  `validation_failures` int unsigned NOT NULL DEFAULT '0',
  `help_sought` int unsigned NOT NULL DEFAULT '0',
  `dwell_p50_ms` int unsigned DEFAULT NULL,
  `dwell_p90_ms` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_flow_daily_key` (`date`,`flow`,`step`,`device_class`,`logged_in`,`is_app`,`variant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_flow_outcome_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `flow` varchar(32) NOT NULL,
  `device_class` varchar(16) NOT NULL DEFAULT '',
  `logged_in` tinyint(1) NOT NULL DEFAULT '0',
  `is_app` tinyint(1) NOT NULL DEFAULT '0',
  `variant` varchar(64) NOT NULL DEFAULT '',
  `started` int unsigned NOT NULL DEFAULT '0',
  `submitted` int unsigned NOT NULL DEFAULT '0',
  `submit_ok` int unsigned NOT NULL DEFAULT '0',
  `submit_failed` int unsigned NOT NULL DEFAULT '0',
  `completed` int unsigned NOT NULL DEFAULT '0',
  `abandoned` int unsigned NOT NULL DEFAULT '0',
  `resumed` int unsigned NOT NULL DEFAULT '0',
  `total_dwell_p50_ms` int unsigned DEFAULT NULL,
  `total_dwell_p90_ms` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_flow_outcome_daily_key` (`date`,`flow`,`device_class`,`logged_in`,`is_app`,`variant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_field_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `flow` varchar(32) NOT NULL,
  `step` varchar(64) NOT NULL,
  `field` varchar(64) NOT NULL,
  `device_class` varchar(16) NOT NULL DEFAULT '',
  `revisits` int unsigned NOT NULL DEFAULT '0',
  `validation_failures` int unsigned NOT NULL DEFAULT '0',
  `choice_switches` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_field_daily_key` (`date`,`flow`,`step`,`field`,`device_class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_component_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `page` varchar(64) NOT NULL,
  `component` varchar(96) NOT NULL,
  `device_class` varchar(16) NOT NULL DEFAULT '',
  `impressions` int unsigned NOT NULL DEFAULT '0',
  `clicks` int unsigned NOT NULL DEFAULT '0',
  `dead_clicks` int unsigned NOT NULL DEFAULT '0',
  `rage_clicks` int unsigned NOT NULL DEFAULT '0',
  `repeated_intent` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_component_daily_key` (`date`,`page`,`component`,`device_class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_feature_adoption_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `feature` varchar(64) NOT NULL,
  `active_users` int unsigned NOT NULL DEFAULT '0',
  `using_users` int unsigned NOT NULL DEFAULT '0',
  `sessions_using` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_feature_adoption_daily_key` (`date`,`feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_search_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `searches` int unsigned NOT NULL DEFAULT '0',
  `zero_result` int unsigned NOT NULL DEFAULT '0',
  `re_searched_60s` int unsigned NOT NULL DEFAULT '0',
  `browse_sessions` int unsigned NOT NULL DEFAULT '0',
  `browse_scrolled_out` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_search_daily_key` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_regret_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `signal` varchar(32) NOT NULL,
  `count` int unsigned NOT NULL DEFAULT '0',
  `base` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_regret_daily_key` (`date`,`signal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_telemetry_health_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `metric` varchar(96) NOT NULL,
  `expected` decimal(14,2) DEFAULT NULL,
  `observed` decimal(14,2) NOT NULL DEFAULT '0.00',
  `ratio` decimal(8,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_telemetry_health_daily_key` (`date`,`metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_sample_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `flow` varchar(32) NOT NULL,
  `step` varchar(64) NOT NULL,
  `signal` varchar(32) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_sample_sessions_lookup` (`date`,`flow`,`step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify: nine rows.
SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ux\_%' ORDER BY table_name;
