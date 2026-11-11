-- Idempotent production SQL for 2026_09_25_000001_add_abuse_to_chat_messages_reportreason.php
--
-- Appends 'Abuse' to chat_messages.reportreason for the Judge's 'decent' question
-- (ai-judgement.md / config('freegle.judgement.chat_reportreason')).
--
-- The value is appended to whatever the column holds now, so a database that already has
-- 'Lockdown' keeps it. Safe to run more than once: it does nothing when 'Abuse' is there.
-- Run once on production BEFORE deploying the code that writes 'Abuse' (ChatProcessService).

SET @has_abuse := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'chat_messages'
      AND column_name = 'reportreason'
      AND column_type LIKE '%''Abuse''%'
);
SET @current := (
    SELECT column_type FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'chat_messages'
      AND column_name = 'reportreason'
);
SET @ddl := IF(@has_abuse = 0 AND @current LIKE 'enum(%',
    CONCAT('ALTER TABLE chat_messages MODIFY COLUMN reportreason ',
           LEFT(@current, CHAR_LENGTH(@current) - 1), ',''Abuse'')',
           ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, ALGORITHM=INPLACE, LOCK=NONE'),
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
