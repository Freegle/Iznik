-- Idempotent production SQL for 2026_07_07_000002_create_rippling_proximity_checked_table.php
--
-- Checked-once-forever negative-memoization marker for ripple:proximity-notes (Phase 0 of
-- plans/routing-performance-step-change.md). A row means the (msgid, groupid) rippled-in copy got
-- a definitive proximity answer (note written, not quicker, or unreachable within budget) and is
-- never re-queried; failed routing calls are NOT marked and retry next run. The command purges
-- rows older than 14 days (candidates only span 8 days of arrivals).
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS. Run before deploying the updated
-- ripple:proximity-notes command (it INSERTs into this table).

CREATE TABLE IF NOT EXISTS rippling_proximity_checked (
    msgid      BIGINT UNSIGNED NOT NULL,
    groupid    BIGINT UNSIGNED NOT NULL,
    checked_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (msgid, groupid),
    INDEX rippling_proximity_checked_checked_at_index (checked_at),
    CONSTRAINT rippling_proximity_checked_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT rippling_proximity_checked_groupid_foreign FOREIGN KEY (groupid)
        REFERENCES `groups` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
