-- Production idempotent SQL: memberships_history.rippled (Rippling Out auto-join mitigations, PR #855).
-- Marks the history row of a ripple auto-join so MembershipsProcessingService suppresses the
-- per-group welcome email (a single bundled intro email is sent instead). The row keeps
-- processingrequired=1 so abuse detection still runs. NOT NULL DEFAULT 0 -> existing rows read as
-- not-rippled. Trailing/positioned bool -> INSTANT add in MySQL 8.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'memberships_history' AND column_name = 'rippled');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE memberships_history ADD COLUMN rippled TINYINT(1) NOT NULL DEFAULT 0 AFTER processingrequired',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
