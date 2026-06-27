-- Production idempotent SQL: memberships.rippled (Rippling Out auto-join mitigations, PR #855).
-- Marks a membership auto-created by a post rippling into that group, so BirthdayService can
-- exclude it and the backfill command can find it. NOT NULL DEFAULT 0 -> every real membership
-- reads as not-rippled. Trailing/positioned bool -> INSTANT add in MySQL 8.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'memberships' AND column_name = 'rippled');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE memberships ADD COLUMN rippled TINYINT(1) NOT NULL DEFAULT 0 AFTER volunteeringallowed',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
