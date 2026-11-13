-- Adds 'Email' to the values messages_history.source allows. Appending a value to the end of an ENUM
-- is a metadata change, so ALGORITHM=INSTANT holds and the table is not rebuilt. Safe to run twice.
SET SESSION lock_wait_timeout = 5;

SET @has := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_history' AND column_name = 'source'
      AND column_type LIKE '%''Email''%'
);
SET @sql := IF(@has = 0,
    "ALTER TABLE messages_history MODIFY COLUMN source ENUM('Yahoo Approved','Yahoo Pending','Yahoo System','Platform','Email') NULL DEFAULT NULL COMMENT 'Source of incoming message', ALGORITHM=INSTANT",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
