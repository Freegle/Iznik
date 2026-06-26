-- Production idempotent SQL: logs(user, groupid, type, subtype) index (Rippling Out, PR #855).
-- Makes the "has this user left this group?" guard (a Group/Left log lookup) a point-lookup so
-- the ripple expander never re-joins, or re-ripples a post into, a group the poster left.
-- Online ADD INDEX in MySQL 8 (ALGORITHM=INPLACE, LOCK=NONE). Guarded so a re-run is a no-op.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'logs' AND index_name = 'ripple_leave_guard');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE logs ADD INDEX ripple_leave_guard (user, groupid, type, subtype), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
