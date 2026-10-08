<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users (deleted, lastaccess): lets the six-month active-user scans range on lastaccess instead of
 * reading the 95% of accounts that merely have deleted IS NULL.
 *
 * Served by it: UnifiedDigestService::getUsersForDigest (daily digest recipients, 182.5-day window,
 * about 7% of users) and NotificationExhortService (lastaccess within minutes, a fraction of 1%).
 *
 * PRODUCTION NOTE. Production is Galera with wsrep_OSU_method=TOI, so the ALTER runs cluster-wide.
 * The index is added by hand BEFORE deploy (see the companion _migration.sql); this migration then
 * finds it already there and does nothing. It only builds the index on databases that do not have
 * it (local, CI, yesterday).
 */
return new class extends Migration
{
    private const TABLE = 'users';

    private const INDEX = 'deleted_lastaccess';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->indexExists()) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ADD INDEX '.self::INDEX.' (deleted, lastaccess), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && $this->indexExists()) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::INDEX);
        }
    }

    private function indexExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, self::INDEX]
        );

        return (int) ($row->n ?? 0) > 0;
    }
};
