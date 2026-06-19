-- Idempotent production SQL for 2026_06_19_000001_create_messages_bulk_outreach_table.php
--
-- Records outreach to a community organisation (a Freegle user) for a specific bulk
-- offer (a messages row): org details, preferences for that offer, and outreach lifecycle.
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS.
-- Run once on production BEFORE deploying the iznik-batch code.

CREATE TABLE IF NOT EXISTS messages_bulk_outreach (
    id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    msgid            BIGINT UNSIGNED  NOT NULL,
    userid           BIGINT UNSIGNED  NOT NULL,
    orgname          VARCHAR(255)     NOT NULL,
    orgtype          VARCHAR(64)      NULL DEFAULT NULL,
    website          VARCHAR(255)     NULL DEFAULT NULL,
    area             VARCHAR(255)     NULL DEFAULT NULL,
    contactname      VARCHAR(255)     NULL DEFAULT NULL,
    tier             ENUM('1','2')    NULL DEFAULT NULL,
    clusters         JSON             NULL DEFAULT NULL,
    preferences      TEXT             NULL DEFAULT NULL,
    activity_url     VARCHAR(1024)    NULL DEFAULT NULL,
    activity_date    DATE             NULL DEFAULT NULL,
    confidence       ENUM('high','med','low') NULL DEFAULT NULL,
    source           VARCHAR(64)      NULL DEFAULT NULL,
    status           ENUM('Imported','Queued','Sent','Replied','Took','Declined','NoResponse','Skipped')
                         NOT NULL DEFAULT 'Imported',
    chatid           BIGINT UNSIGNED  NULL DEFAULT NULL,
    sent_at          TIMESTAMP        NULL DEFAULT NULL,
    sent_via         ENUM('chat','email') NULL DEFAULT NULL,
    replied_at       TIMESTAMP        NULL DEFAULT NULL,
    outcome          VARCHAR(255)     NULL DEFAULT NULL,
    suppressed_until DATE             NULL DEFAULT NULL,
    notes            TEXT             NULL DEFAULT NULL,
    created_at       TIMESTAMP        NULL DEFAULT NULL,
    updated_at       TIMESTAMP        NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY messages_bulk_outreach_msgid_userid_unique (msgid, userid),
    INDEX messages_bulk_outreach_msgid_index (msgid),
    INDEX messages_bulk_outreach_userid_index (userid),
    INDEX messages_bulk_outreach_status_index (status),
    INDEX messages_bulk_outreach_chatid_index (chatid),
    CONSTRAINT messages_bulk_outreach_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT messages_bulk_outreach_userid_foreign FOREIGN KEY (userid)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
