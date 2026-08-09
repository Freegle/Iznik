-- Production idempotent SQL: users_deletions.
--
-- A tombstone per user we have destroyed, so that partners polling /api/changes learn
-- about deletions. Users are otherwise reported from users.lastupdated, which stops
-- working the moment the row is hard-deleted - the member vanishes from the feed and
-- the partner keeps a copy of someone who asked to be gone.
--
-- No foreign key to users, deliberately: the row must survive DELETE FROM users.
CREATE TABLE IF NOT EXISTS users_deletions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userid BIGINT UNSIGNED NOT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    type ENUM('Forgotten', 'Purged') NOT NULL DEFAULT 'Forgotten',
    reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY users_deletions_userid (userid),
    KEY users_deletions_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
