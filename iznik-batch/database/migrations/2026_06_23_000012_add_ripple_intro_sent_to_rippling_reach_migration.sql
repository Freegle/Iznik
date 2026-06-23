-- Production idempotent SQL: rippling_reach.ripple_intro_sent (Rippling Out auto-join mitigations, PR #855).
-- One-shot guard so the bundled intro email (RippleIntroMail) is sent at most once per post,
-- regardless of how many ticks/groups the post ripples into. rippling_reach has one row per
-- rippling post. NOT NULL DEFAULT 0 -> existing rows read as not-yet-sent. Trailing/positioned
-- bool -> INSTANT add in MySQL 8.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'ripple_intro_sent');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE rippling_reach ADD COLUMN ripple_intro_sent TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
