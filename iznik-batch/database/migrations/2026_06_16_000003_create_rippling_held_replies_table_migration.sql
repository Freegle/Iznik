-- Idempotent production SQL for 2026_06_16_000003_create_rippling_held_replies_table.php
--
-- External (email / TrashNothing) replies held because the post hasn't yet
-- rippled to the replier's location (PR C / #3). Named _rippling, NOT _held — a
-- separate chat_messages_held table already exists for a moderator's manual hold.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS.
-- Run once on production BEFORE deploying the held-reply code.

CREATE TABLE IF NOT EXISTS rippling_held_replies (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chatid         BIGINT UNSIGNED NOT NULL,
    chatmsgid      BIGINT UNSIGNED NOT NULL,
    msgid          BIGINT UNSIGNED NOT NULL,
    replieruserid  BIGINT UNSIGNED NOT NULL,
    lat            DOUBLE NULL DEFAULT NULL,
    lng            DOUBLE NULL DEFAULT NULL,
    status         ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    releasedat     TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX rippling_held_replies_msgid_index (msgid),
    INDEX rippling_held_replies_chatid_index (chatid),
    INDEX rippling_held_replies_status_index (status),
    CONSTRAINT rippling_held_replies_chatmsgid_foreign FOREIGN KEY (chatmsgid)
        REFERENCES chat_messages (id) ON DELETE CASCADE,
    CONSTRAINT rippling_held_replies_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
