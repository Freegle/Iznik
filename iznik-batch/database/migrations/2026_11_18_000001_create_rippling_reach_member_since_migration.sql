-- Production idempotent SQL: rippling_reach_member_since, when each member last became eligible
-- for reach mail. A new, empty table; nothing alters an existing table. Run it before the deploy.
CREATE TABLE IF NOT EXISTS rippling_reach_member_since (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  userid BIGINT UNSIGNED NOT NULL,
  since TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY rippling_reach_member_since_userid (userid),
  CONSTRAINT rippling_reach_member_since_userid_foreign FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
