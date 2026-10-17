-- Production idempotent SQL: chat_prompts.userid, chat_prompts.heldchatmsgid and chat_prompts.answered_via.
--
-- A 'confirm_reply' prompt is asked of the replier only (userid) about the reply that waits for their
-- answer (heldchatmsgid), and answered_via says whether the answer was a button or another message. Two
-- statements, because a foreign key added with checks on is added by copying the table: the columns are
-- INSTANT, and the two keys and both foreign keys are INPLACE with foreign_key_checks off (every new value is NULL).
-- Run it with a short lock wait. See the pull request for the row count to check first.
SET SESSION lock_wait_timeout = 5;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'chat_prompts' AND column_name = 'userid');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE chat_prompts
        ADD COLUMN userid BIGINT UNSIGNED NULL AFTER msgids,
        ADD COLUMN heldchatmsgid BIGINT UNSIGNED NULL AFTER userid,
        ADD COLUMN answered_via VARCHAR(16) NULL AFTER answered_at,
        ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET SESSION foreign_key_checks = 0;
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'chat_prompts' AND constraint_name = 'chat_prompts_userid_foreign');
SET @ddl := IF(@has_fk = 0,
    'ALTER TABLE chat_prompts
        ADD KEY chat_prompts_heldchatmsgid (heldchatmsgid),
        ADD KEY chat_prompts_userid (userid),
        ADD CONSTRAINT chat_prompts_userid_foreign FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE,
        ADD CONSTRAINT chat_prompts_heldchatmsgid_foreign FOREIGN KEY (heldchatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE,
        ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET SESSION foreign_key_checks = 1;
