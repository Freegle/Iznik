-- Production idempotent SQL: add 'held' to rippling_reach.status.
-- 'held' freezes a post's ripple when it is pulled back to Pending for review (report /
-- microvolunteering quorum, or moderator Back to Pending): the rippled-in copies are kept as
-- Pending for per-group moderation, so ExpandService's retraction paths skip 'held' posts, and
-- keeping (not deleting) the reach row stops initialiseNew re-reaching + re-notifying on a later
-- re-approval.
SET @has_held := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'rippling_reach'
      AND column_name = 'status'
      AND column_type LIKE '%''held''%'
);
SET @ddl := IF(@has_held = 0,
    "ALTER TABLE rippling_reach MODIFY status ENUM('expanding','stopped','done','held') NOT NULL DEFAULT 'expanding'",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
