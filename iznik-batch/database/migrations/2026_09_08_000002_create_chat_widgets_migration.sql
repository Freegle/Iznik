-- Production idempotent SQL: chat_widgets. One RSU pass. The foreign key is created
-- inside the same guard because MySQL has no ADD CONSTRAINT IF NOT EXISTS.
SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'chat_widgets');
SET @ddl := IF(@has_table = 0,
    "CREATE TABLE chat_widgets (
        chatmsgid BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(32) NOT NULL,
        payload JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (chatmsgid),
        KEY chat_widgets_kind (kind),
        CONSTRAINT chat_widgets_chatmsgid_foreign FOREIGN KEY (chatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Verify: SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'chat_widgets';
