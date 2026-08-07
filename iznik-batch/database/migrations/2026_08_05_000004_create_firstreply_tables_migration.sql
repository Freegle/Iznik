-- Production idempotent SQL: first-reply ledgers.
--
-- firstreply_scouts        - who we told early about a post nobody had replied to yet,
--                            and which signal picked them. Doubles as the per-user
--                            fatigue ledger and the attribution source for "did
--                            scouting actually produce the reply?".
-- firstreply_prompts_sent  - which questions a MEMBER has been asked, and how many of
--                            their posts each covered. Keyed on the member because Freegle
--                            asks about their outstanding posts as a set.
-- firstreply_event_metrics - daily counters, same shape as rippling_event_metrics so the
--                            sysadmin dashboards read both the same way.
SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'firstreply_scouts');
SET @ddl := IF(@has_table = 0,
    "CREATE TABLE firstreply_scouts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        msgid BIGINT UNSIGNED NOT NULL,
        userid BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(16) NOT NULL,
        score FLOAT NOT NULL DEFAULT 0,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        replied_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY firstreply_scouts_msgid_userid (msgid, userid),
        KEY firstreply_scouts_userid_sent (userid, sent_at),
        CONSTRAINT firstreply_scouts_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE,
        CONSTRAINT firstreply_scouts_userid_foreign FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'firstreply_prompts_sent');
SET @ddl := IF(@has_table = 0,
    "CREATE TABLE firstreply_prompts_sent (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        userid BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(32) NOT NULL,
        postcount INT UNSIGNED NOT NULL DEFAULT 1,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY firstreply_prompts_user_kind_sent (userid, kind, sent_at),
        KEY firstreply_prompts_userid_sent (userid, sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'firstreply_event_metrics');
SET @ddl := IF(@has_table = 0,
    "CREATE TABLE firstreply_event_metrics (
        day DATE NOT NULL,
        event VARCHAR(32) NOT NULL,
        count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (day, event)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
