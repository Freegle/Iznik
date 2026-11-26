-- Production idempotent SQL: whatjobs_clicks_daily, the daily WhatJobs click figures beside our own.
-- A new, empty table; nothing alters an existing table. Run it before the deploy.
CREATE TABLE IF NOT EXISTS whatjobs_clicks_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  date DATE NOT NULL,
  billable_clicks INT UNSIGNED NULL,
  first_billable_clicks INT UNSIGNED NULL,
  duplicate_clicks INT UNSIGNED NULL,
  invalid_clicks INT UNSIGNED NULL,
  foreign_clicks INT UNSIGNED NULL,
  latent_clicks INT UNSIGNED NULL,
  applications INT UNSIGNED NULL,
  our_clicks INT UNSIGNED NULL,
  active_users INT UNSIGNED NULL,
  partner_seen_at TIMESTAMP NULL,
  judged_at TIMESTAMP NULL,
  alert VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY whatjobs_clicks_daily_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
