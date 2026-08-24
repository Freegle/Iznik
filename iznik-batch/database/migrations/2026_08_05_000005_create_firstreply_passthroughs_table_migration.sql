-- Production idempotent SQL: firstreply_passthroughs.
--
-- One row per first reply let through instead of held, plus how long that reply
-- would otherwise have waited. A count alone says the lever fired; it does not say
-- whether firing was worth anything, and the average hold duration across all held
-- replies is a different population answering a different question. The number that
-- matters is per reply: for THIS replier at THIS location, when would the reach have
-- got to them? The cached tick schedule can answer that.
--
-- Recording and computing are split on purpose: both the batch app (email/TN) and the
-- Go API (web/app) let replies through, and having each parse tick schedules would put
-- the same geometry in two languages. Both do the cheap INSERT; one sweep fills in
-- waited_hours afterwards.
SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'firstreply_passthroughs');
SET @ddl := IF(@has_table = 0,
    "CREATE TABLE firstreply_passthroughs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        msgid BIGINT UNSIGNED NOT NULL,
        chatmsgid BIGINT UNSIGNED NULL,
        userid BIGINT UNSIGNED NULL,
        source ENUM('web','email','tn') NOT NULL DEFAULT 'web',
        lat DOUBLE NULL,
        lng DOUBLE NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        waited_hours FLOAT NULL,
        computed_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY firstreply_passthroughs_pending (computed_at, created_at),
        KEY firstreply_passthroughs_msgid (msgid),
        CONSTRAINT firstreply_passthroughs_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
