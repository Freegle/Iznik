<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * messages_attachments(externaluid) — for the AI image Accept path.
 *
 * Accepting a regenerated AI image repoints every attachment that used the old image:
 *
 *   UPDATE messages_attachments SET externaluid = ? WHERE externaluid = ?
 *
 * externaluid has no index, so that is a full scan of a 35.9M-row table on every Accept
 * (EXPLAIN: type=ALL, possible_keys=NULL, rows=35,860,240). Measured on prod 2026-08-19,
 * from apiv2's own slow-SQL log at aiimage.go:805: 30.2s, 30.3s, 30.7s, 35.4s, 40.0s,
 * 48.1s, 50.6s and 70.8s — each rewriting only 0-4 rows.
 *
 * That is not merely slow, it is user-visible failure. The API gateway (applb HAProxy)
 * times out at 50s, so the runs above 50s returned an error to ModTools while the UPDATE
 * was still going; the moderator saw "Failed to accept image. Please try again." and
 * retried, starting another 35M-row scan. Reported on the community forum the same day.
 *
 * PRODUCTION NOTE. prod is Percona/Galera with wsrep_OSU_method=TOI, so a plain ALTER
 * serialises cluster-wide writes for the whole index build on a 2.9GB table. Deploy the
 * companion _migration.sql node-by-node under RSU rather than the auto-migrate path.
 *
 * The table is ROW_FORMAT=COMPRESSED (KEY_BLOCK_SIZE=16). ADD INDEX is INPLACE-capable
 * there, so ALGORITHM=INPLACE, LOCK=NONE is what we ask for; if a server refuses it the
 * ALTER errors rather than silently blocking writes, and the companion says what to do.
 *
 * Full column rather than a prefix: a 63,900-row sample of recent rows (id > 45400000)
 * gives 60,628 distinct values and 60,627 distinct 20-char prefixes, 0 NULLs, max length
 * 64, and 99.99% carrying the constant 'freegletusd-' prefix. So externaluid(24) would
 * be roughly a third smaller at the same measured selectivity — worth knowing, but the
 * full column keeps the equality lookup a plain index read with no row recheck, and the
 * table's existing indexes are only 0.8GB.
 *
 * Idempotent: guarded on information_schema so a re-run is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'messages_attachments';

    private const INDEX = 'messages_attachments_externaluid';

    public function up(): void
    {
        if (!$this->tableExists() || $this->indexExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE ' . self::TABLE . ' ADD INDEX ' . self::INDEX . ' (externaluid), ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    public function down(): void
    {
        if (!$this->tableExists() || !$this->indexExists()) {
            return;
        }

        DB::statement('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::INDEX);
    }

    private function tableExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [self::TABLE]
        );

        return (int) ($row->n ?? 0) > 0;
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
