-- Production idempotent SQL: messages_groups.contentcheck_recheck_at.
--
-- "This row has been checked, but its content has changed since, so check it again."
--
-- Editing a message used to re-queue its content check by clearing
-- contentcheck_checked_at. That stamp is also what makes a Pending post visible: the mod
-- Pending list and the work counts both hide rows that have not been checked, so a
-- brand-new post is not shown before the automated checks have had their say. Clearing it
-- on edit therefore made the post the moderator had just edited vanish from their queue -
-- list and badge together - until the next batch pass re-stamped it, and it came back only
-- on a manual reload (Discourse 10001).
--
-- Splitting the two meanings fixes that. "Never checked" stays contentcheck_checked_at IS
-- NULL and still hides the post; "checked, then edited" is this column, and does not. The
-- batch picks up either.
--
-- Nullable with no default, so NULL means "nothing outstanding" and this is safe to run
-- well ahead of the code. No index: the batch only ever looks for it inside the Pending
-- collection or the recent-Approved arrival window, both of which are already indexed and
-- small, so an index on a table this size would cost more to build than it could save.
--
-- INSTANT on Percona 8.0: nullable, no default, added after an existing column.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_groups'
      AND column_name = 'contentcheck_recheck_at');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE messages_groups
        ADD COLUMN contentcheck_recheck_at TIMESTAMP NULL AFTER contentcheck_reasons,
        ALGORITHM=INSTANT',
    'SELECT "messages_groups.contentcheck_recheck_at already present" AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
