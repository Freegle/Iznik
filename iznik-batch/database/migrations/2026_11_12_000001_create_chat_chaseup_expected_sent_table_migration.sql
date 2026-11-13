-- Production idempotent SQL: chat_chaseup_expected_sent, the record of which WAITING FOR REPLY
-- chase-ups chats:chaseup-expected has sent. A new, empty table; nothing alters an existing table.
-- Run it before the deploy.
CREATE TABLE IF NOT EXISTS chat_chaseup_expected_sent (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chatmsgid BIGINT UNSIGNED NOT NULL,
  userid BIGINT UNSIGNED NOT NULL,
  sent TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY chat_chaseup_expected_sent_msg_user (chatmsgid, userid),
  KEY chat_chaseup_expected_sent_sent (sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
