-- Production SQL for 2026_10_19_000003_drop_users_aboutme_userid_index.php
--
-- Order matters. Deploy the API first so no new repeats are written, then run
--   php artisan users:dedupe-aboutme --apply
-- and only then this, which drops the redundant index and rebuilds the table.
-- After the cleanup the table is about 100,000 rows, so the rebuild takes seconds;
-- before it, it would rebuild 3.3 GB under the cluster-wide lock.
SET SESSION lock_wait_timeout = 5;
ALTER TABLE users_aboutme DROP INDEX userid, ALGORITHM=INPLACE, LOCK=NONE;
OPTIMIZE TABLE users_aboutme;
