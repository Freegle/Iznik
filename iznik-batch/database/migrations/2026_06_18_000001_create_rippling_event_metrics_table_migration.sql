-- Production idempotent SQL for rippling_event_metrics (rippling-out live counters, §15/§16).
CREATE TABLE IF NOT EXISTS rippling_event_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    day DATE NOT NULL,
    event VARCHAR(32) NOT NULL,
    count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ripple_event_day_event (day, event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
