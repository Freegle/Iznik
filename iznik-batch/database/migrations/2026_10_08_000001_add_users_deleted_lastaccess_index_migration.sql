-- Production SQL for 2026_10_08_000001_add_users_deleted_lastaccess_index.php
-- users is about 2.9M rows. Not INSTANT-capable (index adds never are). Run in a quiet hour,
-- before deploying; the migration then skips because the index exists.
SET SESSION lock_wait_timeout = 5;
ALTER TABLE users ADD INDEX deleted_lastaccess (deleted, lastaccess), ALGORITHM=INPLACE, LOCK=NONE;
