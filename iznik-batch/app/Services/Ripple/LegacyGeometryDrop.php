<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy-geometry drop sequence, as a thing that can be RUN AGAINST A CLONE.
 *
 * It lives here rather than inline in the migration for one reason: until it
 * did, nothing executed it. CI never sets RIPPLE_DROP_LEGACY_GEOMETRY, and
 * PostDropEraTest fakes the era through LegacyGeometry::fake() rather than
 * issuing any DDL - so the actual ALTER statements were never run by any test,
 * and a version of them that FAILED on its first statement read as verified.
 * Taking a table name means a test can point the real sequence at a
 * `CREATE TABLE ... LIKE rippling_reach` clone and assert the outcome, without
 * destroying the schema every other test depends on.
 *
 * The ordering here is not stylistic. Three MySQL behaviours dictate it, all
 * confirmed on Percona 8.0.43-34:
 *
 *  1. While ANY virtual generated column exists, InnoDB refuses to drop ANY
 *     column by INSTANT or INPLACE - even one nothing derives from ("INPLACE
 *     ADD or DROP of virtual columns cannot be combined with other ALTER TABLE
 *     actions"). So the generated columns come off first and go back after.
 *  2. Dropping a generated column does NOT fail when an index still names it.
 *     It SILENTLY REWRITES that index without the column, so
 *     (status, has_max_reach, updated_at) becomes (status, updated_at) under
 *     the same name. A name-only guard would then decline to re-create it. So
 *     each index is dropped before its column, every time.
 *  3. INSTANT is unavailable on this table regardless, because of the GIS index
 *     on outer_bound that the change deliberately keeps. INPLACE is therefore
 *     the algorithm, and an INPLACE drop rewrites the table - which is what
 *     reclaims the disk, and why there is no separate ALTER ... FORCE.
 */
class LegacyGeometryDrop
{
    /** Generated column, its source column, its index suffix, its index shape. */
    private const GENERATED = [
        ['has_overflow', 'overflow_cells', 'has_overflow', '(has_overflow, updated_at)'],
        ['has_max_reach', 'max_polygon_cells', 'maxreach_candidates', '(status, has_max_reach, updated_at)'],
    ];

    private const LEGACY_COLUMNS = ['polygon_hash', 'max_polygon_hash', 'overflow_bounds', 'polygon', 'max_polygon'];

    /**
     * Run the whole sequence against $table. Index names are derived from the
     * table name, so a clone's indexes never collide with the real table's.
     *
     * @return string[] the statements actually issued, for a test to assert on
     */
    public function run(string $table): array
    {
        $issued = [];

        // Nothing to drop means nothing to do AT ALL, and that early return is
        // load-bearing rather than an optimisation. Without it a re-run would
        // still take both generated columns off and put them back - rebuilding
        // two indexes on a ~50GB table to achieve nothing. An RSU pass is
        // exactly the kind of thing an operator repeats.
        if (!$this->hasAnyLegacyColumn($table)) {
            return $issued;
        }

        // 1. Dedup FKs, then their indexes.
        foreach (['polygon_hash', 'max_polygon_hash'] as $col) {
            $fk = "{$table}_{$col}_foreign";
            if ($this->hasForeignKey($table, $fk)) {
                $issued[] = $this->ddl("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk}`");
            }
        }
        foreach (['polygon_hash', 'max_polygon_hash'] as $col) {
            $idx = "{$table}_{$col}";
            if ($this->hasIndex($table, $idx)) {
                $issued[] = $this->ddl("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
            }
        }

        // 2. Every virtual generated column, index first (reasons 1 and 2).
        foreach (self::GENERATED as [$col, , $idxSuffix]) {
            $idx = "{$table}_{$idxSuffix}";
            if ($this->hasIndex($table, $idx)) {
                $issued[] = $this->ddl("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
            }
            if (Schema::hasColumn($table, $col)) {
                $issued[] = $this->ddl("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
            }
        }

        // 3. The polygon R-tree, before the column it indexes.
        if ($this->hasIndex($table, "{$table}_polygon")) {
            $issued[] = $this->ddl("ALTER TABLE `{$table}` DROP INDEX `{$table}_polygon`");
        }

        // 4. ONE combined drop, which is also the rebuild that reclaims disk.
        $drops = [];
        foreach (self::LEGACY_COLUMNS as $col) {
            if (Schema::hasColumn($table, $col)) {
                $drops[] = "DROP COLUMN `{$col}`";
            }
        }
        if ($drops) {
            $issued[] = $this->ddl(
                "ALTER TABLE `{$table}` ".implode(', ', $drops).', ALGORITHM=INPLACE, LOCK=SHARED'
            );
        }

        // 5. Regenerate the generated columns over the surviving cell columns.
        foreach (self::GENERATED as [$col, $source, $idxSuffix, $cols]) {
            if (!Schema::hasColumn($table, $source)) {
                continue;
            }
            if (!Schema::hasColumn($table, $col)) {
                $issued[] = $this->ddl(
                    "ALTER TABLE `{$table}` ADD COLUMN `{$col}` TINYINT(1) ".
                    "GENERATED ALWAYS AS (`{$source}` IS NOT NULL) VIRTUAL"
                );
            }
            $idx = "{$table}_{$idxSuffix}";
            if (!$this->hasIndex($table, $idx)) {
                $issued[] = $this->ddl("ALTER TABLE `{$table}` ADD INDEX `{$idx}` {$cols}");
            }
        }

        return $issued;
    }

    // keep-raw: DDL. The query builder has no way to express DROP COLUMN with a
    // pinned ALGORITHM/LOCK, a generated-column definition, or a multi-action
    // ALTER - and the pinned algorithm is the whole point of this class.
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

    // keep-raw: information_schema lookup; Schema::hasIndex does not exist and
    // the doctrine/dbal path does not report index names reliably here.
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
