-- Production idempotent SQL: server_samples, one reading of load, processor time, memory and disk
-- for one host. A new, empty table; nothing alters an existing table. Run it before the deploy.
CREATE TABLE IF NOT EXISTS server_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  host VARCHAR(64) NOT NULL,
  sampled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cpus SMALLINT UNSIGNED NOT NULL,
  load1 DECIMAL(8,2) NOT NULL,
  load5 DECIMAL(8,2) NOT NULL,
  load15 DECIMAL(8,2) NOT NULL,
  cpu_total BIGINT UNSIGNED NOT NULL,
  cpu_idle BIGINT UNSIGNED NOT NULL,
  mem_total_mb INT UNSIGNED NOT NULL,
  mem_available_mb INT UNSIGNED NOT NULL,
  swap_total_mb INT UNSIGNED NOT NULL,
  swap_used_mb INT UNSIGNED NOT NULL,
  disks JSON NOT NULL,
  PRIMARY KEY (id),
  KEY server_samples_host_sampled_at (host, sampled_at),
  KEY server_samples_sampled_at (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
