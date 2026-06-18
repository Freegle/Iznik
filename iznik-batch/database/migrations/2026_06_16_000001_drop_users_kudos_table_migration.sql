-- Idempotent production SQL for 2026_06_16_000001_drop_users_kudos_table.php
--
-- Retires the unused "kudos" feature by dropping the users_kudos table.
--
-- Kudos scores were recalculated daily by the `users:update-kudos` job (and the
-- legacy V1 cron/users_kudos.php before it), but the stored values were never
-- read by any live API, front-end, or automated code path. The whole feature has
-- been removed in code in the same change set, so the table is now dead storage.
--
-- Safe to run online and more than once: DROP TABLE IF EXISTS is a no-op if the
-- table is already gone. The only foreign key (users_kudos_ibfk_1, users_kudos.userid
-- -> users.id) lives on this table and is dropped with it; no other table references
-- users_kudos, so there is no incoming constraint to remove first.
--
-- Run once on production AFTER deploying the iznik-batch code that no longer writes
-- to users_kudos (i.e. with this change set). Requires DROP privilege on the iznik
-- database.

DROP TABLE IF EXISTS users_kudos;
