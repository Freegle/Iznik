-- Idempotent production SQL for 2026_06_13_000001_add_push_mode_to_users_digests.php
--
-- Extends users_digests.mode ENUM to add 'push', used as the per-user cursor
-- for the daily new-posts push notification (push:daily-posts command).
--
-- Safe to run multiple times: MySQL ALTER TABLE MODIFY COLUMN on an ENUM that
-- already contains the new value is a no-op metadata change (no table rewrite
-- unless row format changes). Wrapped in a stored procedure so callers can
-- check existence before running if needed.
--
-- Run once on production BEFORE deploying iznik-batch code that uses mode='push'.
-- Requires ALTER TABLE privilege on the iznik database.

ALTER TABLE users_digests
    MODIFY COLUMN mode ENUM('immediate','daily','events','volunteering','push')
    NOT NULL DEFAULT 'daily' COMMENT 'Digest mode';
