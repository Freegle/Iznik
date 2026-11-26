-- Production idempotent SQL: server_job_alerts, the late-job alerts showing each time servers:sample
-- ran. A new, empty table; nothing alters an existing table. Run it before the deploy.
CREATE TABLE IF NOT EXISTS server_job_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sampled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  slug VARCHAR(191) NOT NULL,
  PRIMARY KEY (id),
  KEY server_job_alerts_sampled_at (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
