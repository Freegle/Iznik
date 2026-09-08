-- Production idempotent SQL: assistant_instances. One RSU pass.
CREATE TABLE IF NOT EXISTS assistant_instances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    owner VARCHAR(64) NOT NULL,
    workflow VARCHAR(64) NOT NULL,
    state VARCHAR(64) NOT NULL,
    status ENUM('active','completed','error','paused') NOT NULL DEFAULT 'active',
    context JSON NULL,
    history JSON NULL,
    stay_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY assistant_instances_uuid (uuid),
    KEY assistant_instances_owner (owner),
    KEY assistant_instances_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Verify: SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'assistant_instances';
