-- Production idempotent SQL: chat_messages.idempotencykey (+ unique index), Discourse #9913.
-- Lets CreateChatMessage (iznik-server-go/chat/chatmessage.go) collapse a retried/duplicated
-- chat-send POST onto the same row via INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
-- instead of creating a second row. Nullable column: NULLs never collide in a unique index, so
-- older clients that send no key are unaffected - no backfill needed.
-- Online DDL (ALGORITHM=INPLACE, LOCK=NONE) - same Galera-safe pattern as the existing
-- chat_messages enum-widening migration (2026_05_27_000001). Guarded on BOTH the column and the
-- index (not just the column) so a re-run - including a partial prior run - is a no-op/completion.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'idempotencykey');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE chat_messages ADD COLUMN idempotencykey VARCHAR(64) NULL AFTER message, ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'chat_messages_idempotency_unique');
SET @ddl2 := IF(@idx_exists = 0,
    'ALTER TABLE chat_messages ADD UNIQUE INDEX chat_messages_idempotency_unique (chatid, userid, idempotencykey), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
