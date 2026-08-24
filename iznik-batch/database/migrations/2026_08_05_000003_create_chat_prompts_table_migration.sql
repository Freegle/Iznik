-- Production idempotent SQL: chat_prompts.
--
-- The answerable part of a chat message of type 'Prompt'. The chat message carries the
-- human-readable question; this row carries the machine-readable options and, once the
-- member taps one, their answer. `kind` says what answering it DOES ('delivery' patches
-- messages.deliverypossible, 'deadline' patches messages.deadline, 'views' and 'photo'
-- are informational), and the side effect lives in PromptService keyed off that column.
--
-- CREATE TABLE IF NOT EXISTS makes the table itself idempotent; the foreign keys are
-- created inside the same guard because MySQL has no ADD CONSTRAINT IF NOT EXISTS.
SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'chat_prompts');
SET @ddl := IF(@has_table = 0,
    "CREATE TABLE chat_prompts (
        chatmsgid BIGINT UNSIGNED NOT NULL,
        msgid BIGINT UNSIGNED NULL,
        msgids JSON NULL,
        kind VARCHAR(32) NOT NULL,
        options JSON NULL,
        answer VARCHAR(64) NULL,
        answered_at TIMESTAMP NULL DEFAULT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (chatmsgid),
        KEY chat_prompts_msgid (msgid),
        KEY chat_prompts_kind (kind),
        CONSTRAINT chat_prompts_chatmsgid_foreign FOREIGN KEY (chatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE,
        CONSTRAINT chat_prompts_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
