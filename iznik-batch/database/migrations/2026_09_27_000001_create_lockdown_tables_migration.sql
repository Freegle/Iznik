-- Idempotent production SQL for 2026_09_27_000001_create_lockdown_tables.php
--
-- The lockdown switch: lockdowns (append-only state, newest row is current), lockdown_holds
-- (what an incident held and what became of it), lockdown_counters (what was refused or not
-- sent). chat_messages.reportreason gains 'Lockdown', appended so storage stays one byte;
-- INPLACE + LOCK=NONE, metadata-only on Percona 8.0. Confirm the live ENUM matches the list
-- below before running the ALTER.
-- Run once on production BEFORE deploying the iznik-server-go and iznik-batch code that uses it.

CREATE TABLE IF NOT EXISTS lockdowns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    incidentid BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    surfaces JSON NULL,
    reason VARCHAR(255) NULL,
    notice VARCHAR(20) NULL,
    phrases JSON NULL,
    changedby BIGINT UNSIGNED NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    startedby BIGINT UNSIGNED NULL,
    startedat TIMESTAMP NULL,
    endedby BIGINT UNSIGNED NULL,
    endedat TIMESTAMP NULL,
    endnote TEXT NULL,
    announcedat TIMESTAMP NULL,
    KEY incidentid (incidentid),
    KEY announcedat (announcedat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lockdown_holds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lockdownid BIGINT UNSIGNED NOT NULL,
    kind ENUM('chat','post','chitchat') NOT NULL,
    refid BIGINT UNSIGNED NOT NULL,
    userid BIGINT UNSIGNED NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    risk ENUM('low','risky','spam') NULL,
    releasedat TIMESTAMP NULL,
    outcome VARCHAR(32) NULL,
    UNIQUE KEY kind_refid (kind, refid),
    KEY lockdownid (lockdownid, kind, risk),
    KEY userid (userid),
    KEY releasedat (releasedat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lockdown_counters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lockdownid BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(64) NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY lockdownid_kind (lockdownid, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_lockdown := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'chat_messages'
      AND column_name = 'reportreason'
      AND column_type LIKE '%''Lockdown''%'
);
SET @ddl := IF(@has_lockdown = 0,
    'ALTER TABLE chat_messages MODIFY COLUMN reportreason ENUM(''Spam'',''Other'',''Last'',''Force'',''Fully'',''TooMany'',''User'',''UnknownMessage'',''SameImage'',''DodgyImage'',''CountryBlocked'',''IPUsedForDifferentUsers'',''IPUsedForDifferentGroups'',''SubjectUsedForDifferentGroups'',''SpamAssassin'',''Greetings spam'',''Referenced known spammer'',''Known spam keyword'',''URL on DBL'',''BulkVolunteerMail'',''UsedOurDomain'',''WorryWord'',''Script'',''Link'',''Money'',''Email'',''Language'',''Lockdown'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
