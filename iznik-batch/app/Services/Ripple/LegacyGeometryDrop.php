<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy-geometry drop, in as few schema operations as MySQL allows.
 *
 * THREE statements against rippling_reach, down from fourteen. Every one of
 * those was a separate pass an operator had to run node by node under RSU on a
 * ~50GB table, so collapsing them is the difference between a long afternoon
 * and a short one:
 *
 *   1. Both generated columns and their indexes come off. Metadata only -
 *      virtual columns store nothing.
 *   2. Both dedup foreign keys and all five legacy columns go, in ONE
 *      ALGORITHM=INPLACE alter. THIS is the only statement that does real
 *      work: an INPLACE drop rewrites the table, which is exactly what
 *      reclaims the disk.
 *   3. Both generated columns and both indexes come back, over the surviving
 *      cell columns. Measured: no rebuild (TOTAL_ROW_VERSIONS is unchanged).
 *
 * FEWER STATEMENTS IS NOT AUTOMATICALLY LESS WORK, and that is why this stops
 * at three rather than trying for one. Statements 1 and 3 cannot merge into 2:
 * a virtual column may not be added or dropped in the same alter as anything
 * else ("INPLACE ADD or DROP of virtual columns cannot be combined with other
 * ALTER TABLE actions"), and has_overflow additionally has to go before
 * overflow_bounds, which it derives from. Trying harder would also backfire -
 * measured on the additive side, folding an INSTANT column add into an index
 * build turned a metadata-only change into a full table rebuild that could not
 * even run LOCK=NONE.
 *
 * Two things that used to be separate statements and are now implicit:
 *
 *  - THE SINGLE-COLUMN INDEXES. Dropping a column drops any index over just
 *    that column, so rippling_reach_polygon (the R-tree), _polygon_hash and
 *    _max_polygon_hash need no statement of their own. Verified, not assumed.
 *  - THE FOREIGN KEYS, which ride along in statement 2. Only the two dedup FKs
 *    are named: rippling_reach_shadow_msgid_foreign also exists in production
 *    and must survive.
 *
 * The one ordering rule that is NOT about algorithms: each index is dropped
 * before its generated column, because dropping the column does NOT fail while
 * an index names it - it SILENTLY REWRITES that index without the column, so
 * (status, has_max_reach, updated_at) becomes (status, updated_at) under the
 * same name, and a guard checking only the name would then decline to
 * re-create it. Confirmed on Percona 8.0.43-34.
 *
 * It takes a table name so a test can point the real sequence at a
 * `CREATE TABLE ... LIKE rippling_reach` clone. Until it could, nothing
 * executed these statements at all - CI never sets RIPPLE_DROP_LEGACY_GEOMETRY
 * and the post-drop tests fake the schema guard - which is how a version that
 * died on its first alter passed for verified.
 */
class LegacyGeometryDrop
{
    /** Generated column, source column, index suffix, index shape. */
    private const GENERATED = [
        ['has_overflow', 'overflow_cells', 'has_overflow', '(has_overflow, updated_at)'],
        ['has_max_reach', 'max_polygon_cells', 'maxreach_candidates', '(status, has_max_reach, updated_at)'],
    ];

    /**
     * Dropped together in statement 2. Order matters only for readability; the
     * single-column indexes over them go automatically.
     */
    private const LEGACY_COLUMNS = ['polygon_hash', 'max_polygon_hash', 'overflow_bounds', 'polygon', 'max_polygon'];

    /** The dedup FKs, and ONLY those - shadow_msgid_foreign must survive. */
    private const LEGACY_FOREIGN_KEYS = ['polygon_hash', 'max_polygon_hash'];

    /**
     * @return string[] the statements actually issued, for a test to assert on
     */
    public function run(string $table): array
    {
        $issued = [];

        // Nothing to drop means nothing to do at all. Load-bearing rather than
        // an optimisation: without it a re-run would take both generated
        // columns off and put them back, rebuilding two indexes on a ~50GB
        // table to achieve nothing, and an RSU pass is exactly the sort of
        // thing an operator repeats.
        if (!$this->hasAnyLegacyColumn($table)) {
            return $issued;
        }

        // STATEMENT 1: both generated columns and their indexes. Metadata only.
        $parts = [];
        foreach (self::GENERATED as [$col, , $idxSuffix]) {
            $idx = "{$table}_{$idxSuffix}";
            if ($this->hasIndex($table, $idx)) {
                $parts[] = "DROP INDEX `{$idx}`";
            }
            if (Schema::hasColumn($table, $col)) {
                $parts[] = "DROP COLUMN `{$col}`";
            }
        }
        if ($parts) {
            $issued[] = $this->ddl("ALTER TABLE `{$table}` ".implode(', ', $parts));
        }

        // STATEMENT 2: the FKs and every legacy column, one INPLACE rebuild.
        $parts = [];
        foreach (self::LEGACY_FOREIGN_KEYS as $col) {
            $fk = "{$table}_{$col}_foreign";
            if ($this->hasForeignKey($table, $fk)) {
                $parts[] = "DROP FOREIGN KEY `{$fk}`";
            }
        }
        foreach (self::LEGACY_COLUMNS as $col) {
            if (Schema::hasColumn($table, $col)) {
                $parts[] = "DROP COLUMN `{$col}`";
            }
        }
        if ($parts) {
            $issued[] = $this->ddl(
                "ALTER TABLE `{$table}` ".implode(', ', $parts).', ALGORITHM=INPLACE, LOCK=SHARED'
            );
        }

        // STATEMENT 3: both generated columns and both indexes, restored over
        // the cell columns. No rebuild - virtual columns are metadata and the
        // index builds are additive.
        $parts = [];
        foreach (self::GENERATED as [$col, $source, $idxSuffix, $cols]) {
            if (!Schema::hasColumn($table, $source)) {
                continue;
            }
            if (!Schema::hasColumn($table, $col)) {
                $parts[] = "ADD COLUMN `{$col}` TINYINT(1) GENERATED ALWAYS AS (`{$source}` IS NOT NULL) VIRTUAL";
            }
            $idx = "{$table}_{$idxSuffix}";
            if (!$this->hasIndex($table, $idx)) {
                $parts[] = "ADD INDEX `{$idx}` {$cols}";
            }
        }
        if ($parts) {
            $issued[] = $this->ddl("ALTER TABLE `{$table}` ".implode(', ', $parts));
        }

        return $issued;
    }

    // keep-raw: DDL. The query builder cannot express a pinned ALGORITHM/LOCK,
    // a generated-column definition, or a multi-action ALTER - and combining
    // the actions into one statement is the entire point of this class.
    private function ddl(string $sql): string
    {
        DB::statement($sql);

        return $sql;
    }

    private function hasAnyLegacyColumn(string $table): bool
    {
        foreach (self::LEGACY_COLUMNS as $col) {
            if (Schema::hasColumn($table, $col)) {
                return true;
            }
        }

        return false;
    }

    // keep-raw: information_schema lookup; Schema has no index-existence check.
    private function hasIndex(string $table, string $name): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $name]
        );

        return (int) ($row->n ?? 0) > 0;
    }

    // keep-raw: information_schema lookup, no query-builder equivalent.
    private function hasForeignKey(string $table, string $name): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.referential_constraints
              WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [$table, $name]
        );

        return (int) ($row->n ?? 0) > 0;
    }
}
