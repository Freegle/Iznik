<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema review #1831, items 1.5 and 2.2.
 *
 * Tables: none is referenced by any code in the monorepo, and on production
 * none was opened on db-2 or db-3 in the 14 to 17 days of counters checked on
 * 2026-10-19. users_schedules was last written in 2021; the only thing that
 * still pointed at it was the chat_messages.scheduleid foreign key, on 9 rows.
 * logs_api has no create migration (it exists on production only) and is empty.
 *
 * Indexes: each is an exact copy, or a leading part, of another index on the
 * same table, so every query it could serve is served by the other one, and a
 * foreign key on the same column keeps an index. None had a read on db-2 or db-3
 * except ratings.rater (2 reads).
 *
 * Every statement is metadata only: DROP TABLE, and DROP INDEX / DROP FOREIGN KEY
 * with ALGORITHM=INPLACE. Production refuses LOCK=NONE (error 1845) for each of
 * these index drops, so they use LOCK=SHARED, which lets reads carry on.
 *
 * down() re-adding the chat_messages_ibfk_2 foreign key is a full copy of
 * chat_messages, about 11 GB on production.
 */
return new class extends Migration
{
    private const TABLES = [
        // Children before parents: the simulation tables reference each other.
        'simulation_message_isochrones_users',
        'simulation_message_isochrones_expansions',
        'simulation_message_isochrones_messages',
        'simulation_message_isochrones_sessions', // migrations only; not on production
        'simulation_message_isochrones_runs',
        'transport_duration_model',
        'transport_mode_probabilities',
        'users_dashboard',
        'items_non',
        'mod_bulkops_run',
        'ebay_favourites',
        'groups_twitter',
        'returnpath_seedlist',
        'spatial_ref_sys',
        'users_schedules',
        'logs_api',
    ];

    // table => [index to drop, index that covers it]
    private const INDEXES = [
        'authorities' => ['name_2', 'name'],
        'isochrones' => ['locationid_2', 'userid_2'],
        'messages_outcomes_intended' => ['msgid', 'msgid_2'],
        'shortlink_clicks' => ['shortlinkid', 'shortlinkid_2'],
        'trysts' => ['arrangedfor', 'arrangedfor_2'],
        'stats_outcomes' => ['groupid', 'groupid_2'],
        'ratings' => ['rater', 'rater_2'],
    ];

    public function up(): void
    {
        if ($this->fkExists('chat_messages', 'chat_messages_ibfk_2')) {
            DB::table('chat_messages')->whereNotNull('scheduleid')->update(['scheduleid' => null]);
            DB::statement('ALTER TABLE chat_messages DROP FOREIGN KEY chat_messages_ibfk_2, DROP INDEX scheduleid, ALGORITHM=INPLACE, LOCK=SHARED');
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        foreach (self::INDEXES as $table => [$index]) {
            if ($this->indexExists($table, $index)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`, ALGORITHM=INPLACE, LOCK=SHARED");
            }
        }

        // messages_outcomes_intended carries a second exact copy of msgid.
        if ($this->indexExists('messages_outcomes_intended', 'msgid_3')) {
            DB::statement('ALTER TABLE messages_outcomes_intended DROP INDEX msgid_3, ALGORITHM=INPLACE, LOCK=SHARED');
        }

        // About 7 GB. It served only the sysadmin Recommendations stats, removed
        // with that tab on 2026-10-13; nothing else filters messages_likes on
        // source and timestamp.
        if ($this->indexExists('messages_likes', 'messages_likes_source_ts_user')) {
            DB::statement('ALTER TABLE messages_likes DROP INDEX messages_likes_source_ts_user, ALGORITHM=INPLACE, LOCK=SHARED');
        }
    }

    public function down(): void
    {
        if (! $this->indexExists('messages_likes', 'messages_likes_source_ts_user')) {
            DB::statement('ALTER TABLE messages_likes ADD INDEX messages_likes_source_ts_user (source, timestamp, userid), ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (! $this->indexExists('messages_outcomes_intended', 'msgid_3')) {
            Schema::table('messages_outcomes_intended', fn (Blueprint $t) => $t->index('msgid', 'msgid_3'));
        }

        $columns = [
            'authorities' => ['name'],
            'isochrones' => ['locationid', 'transport', 'minutes', 'source'],
            'messages_outcomes_intended' => ['msgid'],
            'shortlink_clicks' => ['shortlinkid'],
            'trysts' => ['arrangedfor'],
            'stats_outcomes' => ['groupid'],
            'ratings' => ['rater'],
        ];
        foreach (self::INDEXES as $table => [$index]) {
            if (! $this->indexExists($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns[$table], $index));
            }
        }

        // Recreate the tables from their original migrations. The data is not restored.
        $dir = __DIR__;
        foreach (array_reverse(self::TABLES) as $table) {
            if (Schema::hasTable($table)) {
                continue;
            }
            $files = glob("{$dir}/2025_12_10_094529_create_{$table}_table.php");
            if ($table === 'users_schedules') {
                $files = ["{$dir}/2026_07_21_120003_schema_parity_users_schedules_table.php"];
            }
            foreach ($files as $file) {
                (require $file)->up();
            }
        }

        if (! $this->indexExists('chat_messages', 'scheduleid')) {
            Schema::table('chat_messages', fn (Blueprint $t) => $t->index('scheduleid', 'scheduleid'));
        }
        if (Schema::hasTable('users_schedules') && ! $this->fkExists('chat_messages', 'chat_messages_ibfk_2')) {
            DB::statement('ALTER TABLE `chat_messages` ADD CONSTRAINT `chat_messages_ibfk_2`
                 FOREIGN KEY (`scheduleid`) REFERENCES `users_schedules` (`id`) ON DELETE CASCADE');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->n > 0;
    }

    private function fkExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$table, $name]
        )->n > 0;
    }
};
