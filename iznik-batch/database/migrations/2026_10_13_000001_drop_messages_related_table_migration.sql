-- Idempotent production SQL for 2026_10_13_000001_drop_messages_related_table.php
--
-- Drops messages_related. Nothing has written it since V1 was removed on
-- 2026-07-09 and the chase-up no longer reads it (#1210). The table only holds
-- foreign keys TO messages, so dropping it touches nothing else.
-- Run once on production AFTER deploying the iznik-batch code that no longer joins it.
-- The drop takes a metadata lock on messages (the foreign keys point there), so keep
-- the wait short rather than queueing behind a long query on that table.
SET SESSION lock_wait_timeout = 5;
DROP TABLE IF EXISTS messages_related;
