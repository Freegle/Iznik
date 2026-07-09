-- Production SQL: drop the legacy `logos` table.
--
-- The `logos` table backed the special-occasion "doodle" logo variants feature
-- (a random active logo served on a matching calendar day, like a Google Doodle).
-- That feature has been removed from both the client and the V2 API, so nothing
-- reads or writes this table any more.
--
-- Safe to run once on production; safe to re-run (IF EXISTS guard). The table is
-- tiny so the drop is effectively instant.

DROP TABLE IF EXISTS `logos`;
