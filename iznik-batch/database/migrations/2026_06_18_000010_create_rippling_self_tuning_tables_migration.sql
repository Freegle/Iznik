-- Production idempotent SQL: §16 self-tuning tables (rollup, hotspots, advisory params).
CREATE TABLE IF NOT EXISTS rippling_live_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_type ENUM('daily','weekly') NOT NULL DEFAULT 'weekly',
    stratum_type VARCHAR(16) NOT NULL DEFAULT 'overall',
    stratum_key VARCHAR(64) NOT NULL DEFAULT 'all',
    metric VARCHAR(48) NOT NULL,
    value DOUBLE NOT NULL,
    sample_size INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY rippling_live_metrics_uniq (period_start, period_type, stratum_type, stratum_key, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rippling_hotspots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    period_start DATE NOT NULL,
    area_type VARCHAR(16) NOT NULL DEFAULT 'group',
    area_id BIGINT UNSIGNED NULL,
    area_name VARCHAR(128) NULL,
    metric VARCHAR(48) NOT NULL,
    value DOUBLE NOT NULL,
    baseline DOUBLE NOT NULL,
    deviation DOUBLE NOT NULL,
    direction ENUM('high','low') NOT NULL,
    severity ENUM('watch','alert') NOT NULL DEFAULT 'watch',
    KEY rippling_hotspots_period (period_start),
    KEY rippling_hotspots_area (area_type, area_id),
    KEY rippling_hotspots_metric (metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rippling_params (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ons_category VARCHAR(32) NOT NULL,
    curve VARCHAR(24) NULL,
    max_minutes INT NULL,
    target_density INT NULL,
    hazard_schedule JSON NULL,
    status ENUM('active','proposed','rejected') NOT NULL DEFAULT 'proposed',
    rationale VARCHAR(255) NULL,
    proposed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY rippling_params_uniq (ons_category, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
