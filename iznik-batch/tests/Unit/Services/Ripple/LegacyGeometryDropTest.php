<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\LegacyGeometryDrop;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the REAL drop DDL. That is the whole point of this file.
 *
 * The drop is gated behind RIPPLE_DROP_LEGACY_GEOMETRY, which CI never sets,
 * and PostDropEraTest covers the post-drop era by faking the schema guard rather
 * than issuing DDL. So no test executed these ALTER statements - and a version
 * of them that died on its FIRST statement, leaving every legacy column in
 * place, sat in the branch looking verified. It had been checked against a
 * simplified table that carried neither the generated columns nor the GIS index,
 * and both of those are what make MySQL refuse.
 *
 * These tests run the sequence against a `CREATE TABLE ... LIKE rippling_reach`
 * clone, so it is the real table shape - GIS index, generated columns, R-tree
 * and all - without destroying the schema the rest of the suite needs.
 */
class LegacyGeometryDropTest extends TestCase
{
    private const CLONE = 'rr_droptest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeClone();
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS `'.self::CLONE.'`');
        parent::tearDown();
    }

    private function makeClone(): void
    {
        DB::statement('DROP TABLE IF EXISTS `'.self::CLONE.'`');
        DB::statement('CREATE TABLE `'.self::CLONE.'` LIKE rippling_reach');

        // CREATE TABLE ... LIKE copies index NAMES verbatim, so the clone would
        // carry indexes called rippling_reach_*. Rename them to the clone's own
        // prefix, which is what the sequence looks for - and is also how the
        // real table's indexes are named relative to the real table.
        foreach ([
            'has_overflow', 'maxreach_candidates', 'polygon', 'polygon_hash', 'max_polygon_hash', 'outer',
        ] as $suffix) {
            $old = 'rippling_reach_'.$suffix;
            $new = self::CLONE.'_'.$suffix;
            $exists = DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [self::CLONE, $old]
            );
            if ((int) ($exists->n ?? 0) > 0) {
                DB::statement('ALTER TABLE `'.self::CLONE."` RENAME INDEX `{$old}` TO `{$new}`");
            }
        }
    }

    private function columns(): array
    {
        return array_map(
            fn ($r) => $r->c,
            DB::select(
                'SELECT column_name AS c FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [self::CLONE]
            )
        );
    }

    private function indexColumns(string $index): array
    {
        return array_map(
            fn ($r) => $r->c,
            DB::select(
                'SELECT column_name AS c FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                  ORDER BY seq_in_index',
                [self::CLONE, $index]
            )
        );
    }

    /**
     * THE test: the sequence runs to completion and every legacy column is
     * gone. The bug this catches was an ALGORITHM=INSTANT pin that MySQL
     * refuses on this table, which aborted the run at the first DROP COLUMN.
     */
    public function test_the_drop_sequence_runs_and_removes_every_legacy_column(): void
    {
        $issued = (new LegacyGeometryDrop())->run(self::CLONE);

        $this->assertNotEmpty($issued, 'the sequence issued no statements at all');

        $left = array_intersect(
            ['polygon', 'max_polygon', 'overflow_bounds', 'polygon_hash', 'max_polygon_hash'],
            $this->columns()
        );
        $this->assertSame([], array_values($left),
            'legacy columns survived the drop: '.implode(', ', $left));
    }

    /**
     * The drop must be ONE combined ALTER pinned to INPLACE. Five separate
     * drops would rebuild a ~50GB table five times, and INSTANT is refused
     * outright here - so the shape of this statement is the design.
     */
    public function test_the_columns_go_in_a_single_inplace_alter(): void
    {
        $issued = (new LegacyGeometryDrop())->run(self::CLONE);

        $combined = array_values(array_filter($issued, fn ($s) => str_contains($s, 'DROP COLUMN `polygon`')));
        $this->assertCount(1, $combined, 'the geometry columns must be dropped in exactly one statement');

        $sql = $combined[0];
        $this->assertStringContainsString('ALGORITHM=INPLACE', $sql);
        $this->assertStringNotContainsString('ALGORITHM=INSTANT', $sql,
            'INSTANT is refused on this table - it carries a GIS index');
        foreach (['max_polygon', 'overflow_bounds', 'polygon_hash', 'max_polygon_hash'] as $col) {
            $this->assertStringContainsString("DROP COLUMN `{$col}`", $sql,
                "every legacy column belongs in the one rebuild, {$col} was not in it");
        }

        // And no separate FORCE: the INPLACE drop already rewrites the table.
        $this->assertSame([], array_values(array_filter($issued, fn ($s) => str_contains($s, 'FORCE'))),
            'a separate FORCE rebuild is redundant once the drop is INPLACE');
    }

    /**
     * Both generated columns must come back, over the cell columns, with their
     * indexes the right SHAPE. Shape matters and is easy to lose: dropping a
     * generated column silently rewrites an index that names it, leaving the
     * name in place over fewer columns.
     */
    public function test_the_generated_columns_and_their_indexes_are_regenerated(): void
    {
        (new LegacyGeometryDrop())->run(self::CLONE);

        $cols = $this->columns();
        $this->assertContains('has_overflow', $cols);
        $this->assertContains('has_max_reach', $cols);

        $this->assertSame(
            ['has_overflow', 'updated_at'],
            $this->indexColumns(self::CLONE.'_has_overflow'),
            'has_overflow index came back the wrong shape'
        );
        $this->assertSame(
            ['status', 'has_max_reach', 'updated_at'],
            $this->indexColumns(self::CLONE.'_maxreach_candidates'),
            'the maxreach candidate index came back the wrong shape - a silently '
            .'rewritten index keeps its name, so only the columns reveal it'
        );
    }

    /** has_overflow must now derive from the cells, not the dropped column. */
    public function test_has_overflow_is_regenerated_over_the_cells(): void
    {
        (new LegacyGeometryDrop())->run(self::CLONE);

        $row = DB::selectOne(
            'SELECT generation_expression AS e FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [self::CLONE, 'has_overflow']
        );

        $this->assertStringContainsString('overflow_cells', (string) ($row->e ?? ''));
        $this->assertStringNotContainsString('overflow_bounds', (string) ($row->e ?? ''),
            'has_overflow must no longer reference the dropped column');
    }

    /** Re-running must be a no-op, not an error: an RSU pass can be repeated. */
    public function test_the_sequence_is_idempotent(): void
    {
        (new LegacyGeometryDrop())->run(self::CLONE);
        $second = (new LegacyGeometryDrop())->run(self::CLONE);

        $this->assertSame([], $second, 'a second run should have nothing left to do');

        $left = array_intersect(
            ['polygon', 'max_polygon', 'overflow_bounds', 'polygon_hash', 'max_polygon_hash'],
            $this->columns()
        );
        $this->assertSame([], array_values($left));
    }

    /**
     * The GIS index on outer_bound is KEPT - it is the R-tree every SQL-side
     * prefilter drives from, and losing it was the 2026-08-21 outage. It is
     * also the reason INSTANT is unavailable, so it is worth pinning that it
     * is still there afterwards.
     */
    public function test_the_outer_bound_rtree_survives(): void
    {
        (new LegacyGeometryDrop())->run(self::CLONE);

        $this->assertContains('outer_bound', $this->columns(), 'outer_bound must survive');
        $this->assertSame(['outer_bound'], $this->indexColumns(self::CLONE.'_outer'),
            'the outer_bound R-tree must survive the rebuild');
    }

    /** Rows survive, with their cell columns intact. */
    public function test_rows_and_their_cells_survive(): void
    {
        DB::statement(
            'INSERT INTO `'.self::CLONE."` (msgid, lat, lng, polygon, outer_bound, polygon_cells,
                max_polygon_cells, arrival, mode, tick, total_ticks, total_freeglers, max_drive_min,
                status, created_at, updated_at)
             VALUES (1, 51.5, -0.1,
                ST_GeomFromText('POLYGON((-0.1 51.5,-0.09 51.5,-0.09 51.51,-0.1 51.51,-0.1 51.5))', 3857),
                ST_GeomFromText('POLYGON((-0.2 51.4,0 51.4,0 51.6,-0.2 51.6,-0.2 51.4))', 3857),
                'cellbytes', 'maxbytes', NOW(), 'drive', 1, 1, 0, 30, 'done', NOW(), NOW())"
        );

        (new LegacyGeometryDrop())->run(self::CLONE);

        $row = DB::selectOne('SELECT polygon_cells, max_polygon_cells, has_max_reach FROM `'.self::CLONE.'` WHERE msgid = 1');
        $this->assertNotNull($row, 'the row did not survive the rebuild');
        $this->assertSame('cellbytes', $row->polygon_cells);
        $this->assertSame('maxbytes', $row->max_polygon_cells);
        $this->assertSame(1, (int) $row->has_max_reach,
            'the regenerated column must compute from the surviving cells');
    }
}
