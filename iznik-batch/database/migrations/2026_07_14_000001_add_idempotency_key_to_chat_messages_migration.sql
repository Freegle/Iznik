-- Production SQL: add a client-generated idempotency key to chat_messages
-- (Discourse #9913). A retried/duplicated send of the same logical message
-- carries the same key; the unique index makes at-most-once DB-enforced via
-- INSERT ... ON DUPLICATE KEY UPDATE in iznik-server-go/chat/chatmessage.go.
-- Nullable, so older/cached clients that don't send a key are unaffected.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so guard via information_schema.

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'chat_messages'
      AND COLUMN_NAME = 'idempotency_key'
);
SET @ddl := IF(@col = 0,
    'ALTER TABLE chat_messages ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER userid, ADD UNIQUE KEY chat_messages_idempotency_key_unique (chatid, userid, idempotency_key)',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
