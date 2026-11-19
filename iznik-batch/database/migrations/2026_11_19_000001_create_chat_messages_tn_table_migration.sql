-- Production idempotent SQL: chat_messages_tn, which chat message each TrashNothing chat-messages
-- feed record became. A new, empty table; the foreign key is on the new table, so nothing alters
-- chat_messages. Run it before the deploy.
CREATE TABLE IF NOT EXISTS chat_messages_tn (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chatmsgid BIGINT UNSIGNED NOT NULL,
  tnmessageid VARCHAR(64) NOT NULL,
  tndate TIMESTAMP NULL DEFAULT NULL,
  created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY chat_messages_tn_tnmessageid (tnmessageid),
  KEY chat_messages_tn_chatmsgid (chatmsgid),
  CONSTRAINT chat_messages_tn_chatmsgid_fk FOREIGN KEY (chatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
