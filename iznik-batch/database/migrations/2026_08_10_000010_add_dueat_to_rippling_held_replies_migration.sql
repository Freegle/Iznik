-- Production idempotent SQL: rippling_held_replies.dueat.
--
-- When a held reply is due to be delivered. A hold is a delay, and this is when it
-- ends: computed from how far the replier is from the item. Locals still go first;
-- nobody is invisible for days. Waiting for the reach to arrive instead is no delay
-- at all for most repliers, because three in four of them live somewhere it never
-- covers, so they sit on the max-reach backstop days later - by which time a quarter
-- to a third of items have gone.
--
-- Nullable because the Go/web hold path does not compute it - the release sweep stamps
-- it on its first pass, so the delay policy lives in one place (PHP). NULL means "not
-- yet stamped". Rows already held when this lands are stamped the same way, which is
-- what releases the existing backlog promptly instead of stranding it.
--
-- Not to be confused with the existing releasedat: dueat is when the row is due to
-- come off hold, releasedat is when it actually came off.
--
-- INSTANT on Percona 8.0: nullable, no default, added after an existing column.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_held_replies'
      AND column_name = 'dueat');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_held_replies
        ADD COLUMN dueat TIMESTAMP NULL AFTER status,
        ALGORITHM=INSTANT',
    'SELECT "rippling_held_replies.dueat already present" AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
