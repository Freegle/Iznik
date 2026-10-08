-- Production SQL for 2026_10_08_000002_add_jobs_visible_cpc_index.php
-- jobs is about 0.75M rows, ROW_FORMAT=COMPRESSED. Not INSTANT-capable. Run in a quiet hour,
-- one at a time after the users index, before deploying.
SET SESSION lock_wait_timeout = 5;
ALTER TABLE jobs ADD INDEX visible_cpc (visible, cpc), ALGORITHM=INPLACE, LOCK=NONE;
