-- Production idempotent SQL: append 'Prompt' to chat_messages.type.
--
-- A Prompt is a question Freegle asks a member inside an ordinary chat, with a small
-- set of tappable answers. The question text lives in chat_messages.message like any
-- other message, so email, push, mod review and search need no changes; the tappable
-- part lives in the chat_prompts side table keyed on chatmsgid. Side table rather than
-- new columns because chat_messages is one of the largest tables here and only a
-- vanishing fraction of rows will ever be prompts.
--
-- End-append keeps every existing value's ordinal stable, so this is metadata-only.
-- ALGORITHM=INPLACE, LOCK=NONE (INSTANT is not supported for ENUM modification on
-- Percona/Galera). Galera TOI still pauses cluster writes for the duration, which is
-- ms-scale for a metadata-only change. Guarded so a re-run is a no-op.
SET @has_value := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'chat_messages'
      AND column_name = 'type' AND column_type LIKE '%Prompt%');
SET @ddl := IF(@has_value = 0,
    "ALTER TABLE chat_messages MODIFY COLUMN `type` ENUM('Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated','Reminder','Prompt') NOT NULL DEFAULT 'Default', ALGORITHM=INPLACE, LOCK=NONE",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
