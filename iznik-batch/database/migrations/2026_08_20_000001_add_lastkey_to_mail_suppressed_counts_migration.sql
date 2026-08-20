-- Production idempotent SQL: mail_suppressed_counts.lastkey
--
-- WHY. `count` is displayed on the support screen as "Held" and is supposed to
-- mean "mails we declined to generate". It counts ATTEMPTS. The chat notifier
-- skips a suppressed recipient without advancing chat_roster.lastmsgemailed, so
-- every run re-processes the same unread messages and increments again.
--
-- Prod 2026-08-20: user 3546689 = 10,777 for `chat` across 106 minutes (101.7
-- per minute); user 44607900 = 11,691 across 133 minutes. Nobody receives a
-- hundred emails a minute.
--
-- lastkey stores the highest per-mail identity counted (for chat, the chat
-- message id). Ids increase, so replaying the same backlog cannot advance it,
-- while a new message can.
--
-- INSTANT add on a small table; no Galera stall worth planning around, but it
-- is guarded so a re-run is a no-op.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'mail_suppressed_counts'
      AND column_name = 'lastkey');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE mail_suppressed_counts ADD COLUMN lastkey BIGINT UNSIGNED NULL COMMENT ''Highest per-mail identity counted, so retries of the same mail do not re-count'', ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- AFTERWARDS. Existing rows keep their inflated totals; they are historical
-- attempt counts and there is no way to recover the true mail count from them.
-- If the support screen should start clean rather than showing a number nobody
-- can act on, zero the open episodes ONE ROW AT A TIME (Galera):
--
--   SELECT id FROM mail_suppressed_counts WHERE caughtup_at IS NULL ORDER BY id;
--   -- then per id:
--   UPDATE mail_suppressed_counts SET `count` = 1 WHERE id = <id>;
