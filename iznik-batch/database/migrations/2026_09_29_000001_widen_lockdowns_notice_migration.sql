-- Idempotent production SQL for 2026_09_29_000001_widen_lockdowns_notice.php
-- lockdowns.notice holds the member notice text itself. Safe to run twice.
-- Run once on production, after 2026_09_27_000001, BEFORE deploying the code that uses it.
ALTER TABLE lockdowns MODIFY COLUMN notice TEXT NULL;
