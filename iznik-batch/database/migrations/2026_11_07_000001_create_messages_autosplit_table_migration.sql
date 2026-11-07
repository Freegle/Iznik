-- Production idempotent SQL: messages_autosplit (auto-split of multi-item posts).
-- A new, empty table; nothing alters an existing table. Safe to run before the deploy.
CREATE TABLE IF NOT EXISTS messages_autosplit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  msgid BIGINT UNSIGNED NOT NULL,
  state ENUM('Shadow','Split','NotSplit','Undone') NOT NULL,
  kind VARCHAR(32) NULL COMMENT 'The text splitter''s kind: single, set, bundle, several_items...',
  items JSON NULL COMMENT 'The text splitter''s item list',
  photos JSON NULL COMMENT 'What each photo was decided to be',
  createdattachments JSON NULL COMMENT 'messages_attachments ids of the crops the split added',
  originalavailableinitially INT UNSIGNED NULL,
  originalavailablenow INT UNSIGNED NULL,
  model VARCHAR(64) NULL,
  tokensin INT UNSIGNED NOT NULL DEFAULT 0,
  tokensout INT UNSIGNED NOT NULL DEFAULT 0,
  created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undoneat TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY messages_autosplit_msgid (msgid),
  KEY messages_autosplit_state_created (state, created),
  CONSTRAINT messages_autosplit_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
