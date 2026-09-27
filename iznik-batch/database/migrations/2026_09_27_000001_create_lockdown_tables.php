<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lockdown switch (plans/active/2026-09-27-lockdown-switch.md, section 10).
 *
 * lockdowns is append-only: one row per change, the history is the audit, and the current
 * state is the newest row. incidentid is the id of the row that pressed the switch, carried
 * on every later row of the same incident; holds and counters hang off it.
 *
 * surfaces is a JSON object of the surfaces currently held: chat, chat_mode (hard|soft),
 * posts, chitchat, events, email, push, export, mods.
 *
 * lockdown_holds is one row per chat message, post or ChitChat post held by an incident,
 * with its triage class and what became of it. lockdown_counters counts what was refused or
 * not sent (kind 'email:<type>', 'push', 'export', 'refused:<userid>', 'approved:<userid>').
 *
 * chat_messages.reportreason gains 'Lockdown', appended so the ENUM stays one byte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lockdowns')) {
            DB::statement("
                CREATE TABLE lockdowns (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('lockdown_holds')) {
            DB::statement("
                CREATE TABLE lockdown_holds (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('lockdown_counters')) {
            DB::statement("
                CREATE TABLE lockdown_counters (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    lockdownid BIGINT UNSIGNED NOT NULL,
                    kind VARCHAR(64) NOT NULL,
                    count INT UNSIGNED NOT NULL DEFAULT 0,
                    UNIQUE KEY lockdownid_kind (lockdownid, kind)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (Schema::hasTable('chat_messages')) {
            $type = DB::selectOne("SHOW COLUMNS FROM chat_messages LIKE 'reportreason'")->Type ?? '';
            if (!str_contains($type, "'Lockdown'")) {
                DB::statement("
                    ALTER TABLE chat_messages
                      MODIFY COLUMN reportreason ENUM(
                        'Spam','Other','Last','Force','Fully','TooMany','User',
                        'UnknownMessage','SameImage','DodgyImage',
                        'CountryBlocked',
                        'IPUsedForDifferentUsers',
                        'IPUsedForDifferentGroups',
                        'SubjectUsedForDifferentGroups',
                        'SpamAssassin',
                        'Greetings spam',
                        'Referenced known spammer',
                        'Known spam keyword',
                        'URL on DBL',
                        'BulkVolunteerMail',
                        'UsedOurDomain',
                        'WorryWord',
                        'Script',
                        'Link',
                        'Money',
                        'Email',
                        'Language',
                        'Lockdown'
                      ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                      ALGORITHM=INPLACE, LOCK=NONE
                ");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lockdown_counters');
        Schema::dropIfExists('lockdown_holds');
        Schema::dropIfExists('lockdowns');
    }
};
