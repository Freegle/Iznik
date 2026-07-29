<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align indexes with production.
     *
     * Found by comparing information_schema.statistics between live and a
     * freshly-migrated database, keyed on (table, column list, uniqueness) so
     * that purely cosmetic name differences are ignored. These are the genuine
     * structural gaps: indexes production relies on that a migrated database
     * would not have, plus two where the column list or uniqueness differs.
     *
     * users.suspectcount is deliberately NOT recreated here - production has it
     * but it is unused, and 2026_07_21_120006 drops it.
     */
    private function indexExists(string $table, string $index): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function addIndex(string $table, string $index, string $cols, bool $unique = false): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }
        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD %s `%s` (%s)',
            $table,
            $unique ? 'UNIQUE KEY' : 'KEY',
            $index,
            $cols
        ));
    }

    private function dropIndex(string $table, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
        }
    }

    public function up(): void
    {
        // --- straightforward additions: present on live, absent from migrations ---
        $this->addIndex('ai_images', 'idx_externaluid', '`externaluid`');
        $this->addIndex('email_tracking_clicks', 'action', '`action`');
        $this->addIndex('jobs', 'city', '`city`, `state`, `country`');
        $this->addIndex('memberships', 'idx_configid_role_groupid', '`configid`, `role`, `groupid`');
        $this->addIndex('messages_spatial', 'groupid_2', '`groupid`, `msgtype`, `arrival`');
        $this->addIndex('users_thanks', 'userid_timestamp', '`userid`, `timestamp`');

        // users.tnuserid is UNIQUE on production and is queried by exact match on
        // every TrashNothing partner-auth request (user/partner.go, membership.go,
        // message.go). The migrations declared the column with no index at all.
        $this->addIndex('users', 'tnuserid', '`tnuserid`', true);

        // --- definition mismatches ---

        // Live: (timestamp, userid). Migrations: (timestamp). The composite is what
        // the daily microvolunteering:score job scans (WHERE timestamp > ? then
        // DISTINCT userid), so the trailing column makes it covering.
        if ($this->indexExists('microactions', 'timestamp')) {
            $existing = DB::selectOne(
                "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'microactions' AND index_name = 'timestamp'"
            );
            if (($existing->cols ?? '') !== 'timestamp,userid') {
                $this->dropIndex('microactions', 'timestamp');
                $this->addIndex('microactions', 'timestamp', '`timestamp`, `userid`');
            }
        }

        // Live: non-unique KEY userid. Migrations: UNIQUE. A user can thank more
        // than once, so UNIQUE is wrong and would reject legitimate inserts.
        $uniqueOnLocal = DB::selectOne(
            "SELECT MIN(non_unique) AS nu FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'users_thanks' AND index_name = 'userid'"
        );
        if (Schema::hasTable('users_thanks') && (int) ($uniqueOnLocal->nu ?? 1) === 0) {
            $this->dropIndex('users_thanks', 'userid');
            $this->addIndex('users_thanks', 'userid', '`userid`');
        }
    }

    public function down(): void
    {
        foreach ([
            ['ai_images', 'idx_externaluid'],
            ['email_tracking_clicks', 'action'],
            ['jobs', 'city'],
            ['memberships', 'idx_configid_role_groupid'],
            ['messages_spatial', 'groupid_2'],
            ['users_thanks', 'userid_timestamp'],
            ['users', 'tnuserid'],
        ] as [$table, $index]) {
            $this->dropIndex($table, $index);
        }
    }
};
