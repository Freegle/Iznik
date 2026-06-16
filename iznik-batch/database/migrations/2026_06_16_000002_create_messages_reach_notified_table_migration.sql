-- Idempotent production SQL for 2026_06_16_000002_create_messages_reach_notified_table.php
--
-- Ledger of members already sent the immediate "new post near you" mail for a
-- rippling post (PR B), so expansion steps never re-notify someone. Composite PK
-- (msgid, userid) makes re-notification impossible by construction.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS.
-- Run once on production BEFORE deploying the immediate-mail code.

CREATE TABLE IF NOT EXISTS messages_reach_notified (
    msgid       BIGINT UNSIGNED NOT NULL,
    userid      BIGINT UNSIGNED NOT NULL,
    notified_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (msgid, userid),
    INDEX messages_reach_notified_userid_index (userid),
    CONSTRAINT messages_reach_notified_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT messages_reach_notified_userid_foreign FOREIGN KEY (userid)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
