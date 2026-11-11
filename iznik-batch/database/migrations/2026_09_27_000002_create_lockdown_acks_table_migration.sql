-- Idempotent production SQL for 2026_09_27_000002_create_lockdown_acks_table.php
-- lockdown_acks: which lockdowns row each batch loop has acted on, and when.
-- Run once on production BEFORE deploying the iznik-batch and iznik-server-go code that uses it.
CREATE TABLE IF NOT EXISTS lockdown_acks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `loop` VARCHAR(64) NOT NULL,
    lockdownrowid BIGINT UNSIGNED NOT NULL,
    seenat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `loop` (`loop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
