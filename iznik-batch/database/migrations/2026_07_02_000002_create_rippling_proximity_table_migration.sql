-- Idempotent production SQL for 2026_07_02_000002_create_rippling_proximity_table.php
--
-- Moderator "quicker to get to" P/Q note for a post that has rippled into a group, computed once
-- (best-effort, out of the hot ripple:expand cron) by ripple:proximity-notes and served by apiv2.
-- Kept in its own rippling_* table rather than as columns on the hot messages_groups table.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS. apiv2's read is tolerant of this table
-- not existing yet, so ordering is not critical; run it before ripple:proximity-notes starts.

CREATE TABLE IF NOT EXISTS rippling_proximity (
    msgid       BIGINT UNSIGNED NOT NULL,
    groupid     BIGINT UNSIGNED NOT NULL,
    p           VARCHAR(160)    NOT NULL,
    q           VARCHAR(160)    NOT NULL,
    computed_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (msgid, groupid),
    INDEX rippling_proximity_groupid_index (groupid),
    CONSTRAINT rippling_proximity_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT rippling_proximity_groupid_foreign FOREIGN KEY (groupid)
        REFERENCES `groups` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
