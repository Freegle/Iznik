<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align foreign keys with production.
     *
     * Four constraints exist on live but not in the migrations. They matter more
     * than ordinary drift because InnoDB requires an index to back each FK, so a
     * missing constraint makes an index look droppable when it is mandatory.
     *
     * One constraint goes the other way - alerts(groupid) -> groups(id) exists in
     * the migrations but not on live - and is removed here so a migrated database
     * matches production.
     */
    private function fkExists(string $table, string $name): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    public function up(): void
    {
        // chat_messages.scheduleid -> users_schedules.id (CASCADE).
        // Requires 2026_07_21_120003 to have created users_schedules.
        if (Schema::hasTable('users_schedules') && !$this->fkExists('chat_messages', 'chat_messages_ibfk_2')) {
            DB::statement(
                'ALTER TABLE `chat_messages` ADD CONSTRAINT `chat_messages_ibfk_2`
                 FOREIGN KEY (`scheduleid`) REFERENCES `users_schedules` (`id`) ON DELETE CASCADE'
            );
        }

        if (!$this->fkExists('email_tracking', 'email_tracking_ibfk_1')) {
            DB::statement(
                'ALTER TABLE `email_tracking` ADD CONSTRAINT `email_tracking_ibfk_1`
                 FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL'
            );
        }

        if (!$this->fkExists('email_tracking', 'email_tracking_ibfk_2')) {
            DB::statement(
                'ALTER TABLE `email_tracking` ADD CONSTRAINT `email_tracking_ibfk_2`
                 FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL'
            );
        }

        if (!$this->fkExists('stats_outcomes', 'stats_outcomes_ibfk_1')) {
            DB::statement(
                'ALTER TABLE `stats_outcomes` ADD CONSTRAINT `stats_outcomes_ibfk_1`
                 FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE'
            );
        }

        // Present in migrations, absent on live.
        if ($this->fkExists('alerts', 'alerts_groupid_foreign')) {
            Schema::table('alerts', function (Blueprint $table) {
                $table->dropForeign('alerts_groupid_foreign');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['chat_messages', 'chat_messages_ibfk_2'],
            ['email_tracking', 'email_tracking_ibfk_1'],
            ['email_tracking', 'email_tracking_ibfk_2'],
            ['stats_outcomes', 'stats_outcomes_ibfk_1'],
        ] as [$table, $name]) {
            if ($this->fkExists($table, $name)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
            }
        }

        if (Schema::hasTable('alerts') && !$this->fkExists('alerts', 'alerts_groupid_foreign')) {
            Schema::table('alerts', function (Blueprint $table) {
                $table->foreign(['groupid'], 'alerts_groupid_foreign')
                    ->references(['id'])->on('groups')->onDelete('cascade');
            });
        }
    }
};
