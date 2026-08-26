<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\LegacyGeometry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ripple:verify-cells-parity is the evidence that the cell grids answer every
 * reach question the way the polygons did, so the property that matters most
 * is that IT CAN FAIL. A verification tool which passes on a genuinely wrong
 * grid is worse than no tool, because it is quoted as proof.
 *
 * So the central test here plants a grid that describes a DIFFERENT area from
 * its row's polygon and asserts the command reports failure. Two earlier
 * versions of this command would have passed that test for the wrong reasons -
 * one measured every distance as null and printed "worst 0.0m", the other
 * invented polygon edges that pulled measured distances below the threshold -
 * and both were found by running it rather than reading it.
 *
 * These tests make REAL calls to the spatial server's rasterise endpoint
 * (CellSetService::rasterize): the command compares stored bytes against
 * stored geometry, so faking the bytes would prove nothing about whether the
 * comparison works.
 */
class VerifyCellsParityCommandTest extends TestCase
{
    /** A 0.02 x 0.02 degree box - about 66 x 66 cells. */
    private const REACH = 'POLYGON((-0.10 51.50, -0.08 51.50, -0.08 51.52, -0.10 51.52, -0.10 51.50))';

    /** Somewhere else entirely, for the mismatch case. */
    private const ELSEWHERE = 'POLYGON((1.00 52.00, 1.02 52.00, 1.02 52.02, 1.00 52.02, 1.00 52.00))';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        LegacyGeometry::reset();
        DB::statement('DELETE FROM rippling_reach');
    }

    protected function tearDown(): void
    {
        LegacyGeometry::reset();
        parent::tearDown();
    }

    /**
     * A row carrying a polygon and the cells rasterised from a DIFFERENT WKT,
     * so the two genuinely disagree. Passing $cellsWkt === $polyWkt gives an
     * honest row.
     */
    private function seedRow(string $polyWkt, string $cellsWkt): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        $cells = app(CellSetService::class)->rasterize($cellsWkt);
        if ($cells === null) {
            $this->fail('the spatial rasteriser is unreachable, so this test cannot prove anything');
        }

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, polygon_cells, outer_bound, arrival, mode, tick,
                total_ticks, total_freeglers, max_drive_min, status, created_at, updated_at)
             VALUES (?, 51.51, -0.09, ST_GeomFromText(?, 3857), ?,
                     ST_Buffer(ST_Simplify(ST_GeomFromText(?, 3857), 0.002), 0.002),
                     NOW(), 'drive', 1, 1, 0, 30, 'done', NOW(), NOW())",
            [$msgid, $polyWkt, $cells, $polyWkt]
        );

        return $msgid;
    }

    /**
     * THE test. A grid describing a different area must be caught, and caught
     * for the right reason - a containment difference far from the boundary,
     * not a rounding complaint.
     */
    public function test_a_grid_that_disagrees_with_its_polygon_is_reported_as_a_failure(): void
    {
        $this->seedRow(self::REACH, self::ELSEWHERE);

        $this->artisan('ripple:verify-cells-parity', ['--limit' => 1, '--boundary-points' => 20])
            ->expectsOutputToContain('FAILURES')
            ->assertExitCode(1);
    }

    /** An honest row must pass, or the command is useless as a gate. */
    public function test_a_grid_matching_its_polygon_passes(): void
    {
        $this->seedRow(self::REACH, self::REACH);

        $this->artisan('ripple:verify-cells-parity', ['--limit' => 1, '--boundary-points' => 20])
            ->expectsOutputToContain('No failures')
            ->assertExitCode(0);
    }

    /**
     * Unreadable bytes must FAIL, not be quietly counted and passed over. A
     * row whose grid cannot be decoded has no reach at all once the polygon
     * is dropped, so it is exactly what this command exists to surface -
     * every probe answering "cannot say" is not agreement.
     */
    public function test_unreadable_cells_are_a_failure(): void
    {
        $msgid = $this->seedRow(self::REACH, self::REACH);
        DB::statement('UPDATE rippling_reach SET polygon_cells = ? WHERE msgid = ?',
            ['not a cell set at all', $msgid]);

        $this->artisan('ripple:verify-cells-parity', ['--limit' => 1, '--boundary-points' => 10])
            ->expectsOutputToContain('grid could not say')
            ->assertExitCode(1);

        // And it is a read-only check: nothing about the row changes.
        $this->assertSame(
            'not a cell set at all',
            DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells'),
            'the command must not write anything'
        );
    }

    /** Nothing to compare is a failure, not a vacuous pass. */
    public function test_no_comparable_rows_is_a_failure(): void
    {
        $this->artisan('ripple:verify-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('nothing to compare')
            ->assertExitCode(1);
    }

    /**
     * Post-drop the command has nothing to compare against and must say so
     * rather than crash on a column that no longer exists.
     */
    public function test_refuses_once_the_polygon_column_is_gone(): void
    {
        $this->seedRow(self::REACH, self::REACH);
        LegacyGeometry::fake(polygon: false);

        $this->artisan('ripple:verify-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('has been dropped')
            ->assertExitCode(1);
    }

    /**
     * The report must be machine-readable as well as printed, because the
     * numbers are quoted: --json is what makes a claim checkable afterwards.
     */
    public function test_writes_a_json_report_with_the_per_case_numbers(): void
    {
        $this->seedRow(self::REACH, self::REACH);
        $path = tempnam(sys_get_temp_dir(), 'parity') ?: '/tmp/parity-test.json';

        $this->artisan('ripple:verify-cells-parity', [
            '--limit' => 1,
            '--boundary-points' => 20,
            '--json' => $path,
        ])->assertExitCode(0);

        $this->assertFileExists($path);
        $report = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('summary', $report);

        $c = $report['summary']['containment'];
        $this->assertGreaterThan(0, $c['probes'], 'a report with no probes proves nothing');

        // Every difference must be MEASURED. An unmeasured one is counted as a
        // failure precisely because an earlier version reported "worst 0.0m"
        // while measuring nothing at all.
        $this->assertSame(0, $c['unmeasured']);
        foreach (array_keys($c['measuredBy'] ?? []) as $how) {
            $this->assertSame('nearest-edge', $how, 'distances must come from real polygon edges');
        }

        // And the bands must be reported separately: a headline that mixes the
        // boundary band into the total hides where the differences are.
        $this->assertArrayHasKey('boundary', $c['byBand']);
        $this->assertArrayHasKey('interior', $c['byBand']);
        $this->assertArrayHasKey('exterior', $c['byBand']);
    }

    /**
     * Interior probes must be points the polygon actually contains. They used
     * to be derived from the bounding box, which for a ragged isochrone puts
     * some of them OUTSIDE - making a clean "interior 0 disagreements" figure
     * mean much less than it read.
     */
    public function test_interior_probes_are_inside_the_polygon(): void
    {
        $this->seedRow(self::REACH, self::REACH);
        $path = tempnam(sys_get_temp_dir(), 'parity') ?: '/tmp/parity-interior.json';

        $this->artisan('ripple:verify-cells-parity', [
            '--limit' => 1,
            '--boundary-points' => 0,
            '--interior-points' => 6,
            '--exterior-points' => 0,
            '--json' => $path,
        ])->assertExitCode(0);

        $report = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $band = $report['summary']['containment']['byBand'];
        $this->assertGreaterThan(0, $band['interior']['probes']);
        $this->assertSame(0, $band['boundary']['probes']);
        $this->assertSame(0, $band['exterior']['probes']);

        // A box polygon contains every interior candidate, so all of them
        // should have been kept AND all should agree.
        $this->assertSame(0, $band['interior']['disagree']);
    }

    /**
     * A group whose area only TOUCHES the reach must be measurable.
     *
     * This is the case that took the whole report down on production
     * (2026-08-26): the group-relations check measured the overlap as
     * ST_Area(ST_Intersection(...)), and the intersection of two polygons
     * that meet along a boundary is a LINESTRING or a GEOMETRYCOLLECTION -
     * which ST_Area refuses outright with "ERROR 3516: POLYGON/MULTIPOLYGON
     * value is a geometry of unexpected type GEOMCOLLECTION in st_area". The
     * command aborted on the FIRST sampled row, so the parity gate the drop
     * migration depends on could not be run at all.
     *
     * Nothing in this suite caught it because createTestGroup() leaves
     * polyindex NULL, and the check skips groups without one - so every
     * existing test ran the group-relations case against zero groups and
     * passed. This test gives a group a real polyindex sharing an edge with
     * the reach, and asserts BOTH that the command survives and that the
     * check genuinely ran (tests > 0), since a vacuous pass is what hid the
     * bug in the first place.
     */
    public function test_a_group_sharing_only_a_boundary_with_the_reach_is_measured_not_fatal(): void
    {
        $msgid = $this->seedRow(self::REACH, self::REACH);

        // Butted up against the reach's eastern edge at lng -0.08: the two
        // polygons share that edge exactly and overlap in no area at all.
        $group = $this->createTestGroup();
        DB::statement(
            'UPDATE `groups` SET polyindex = ST_GeomFromText(?, 3857) WHERE id = ?',
            ['POLYGON((-0.08 51.50, -0.06 51.50, -0.06 51.52, -0.08 51.52, -0.08 51.50))', $group->id]
        );

        $path = tempnam(sys_get_temp_dir(), 'parity') ?: '/tmp/parity-touching.json';

        $this->artisan('ripple:verify-cells-parity', [
            '--limit' => 1,
            '--boundary-points' => 10,
            '--json' => $path,
        ])->assertExitCode(0);

        $report = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $rel = $report['summary']['groupRelations'];
        $this->assertGreaterThan(
            0,
            $rel['tests'],
            'the group-relations case did not run, so this test would pass with the bug still present'
        );

        // Touching is not overlapping: whatever the two sides say about
        // intersects, an edge-only meeting can never be a FAILURE, because
        // there is no area for the lattice to have got wrong.
        $this->assertSame(0, $rel['failures']);

        // Read-only, like every other case.
        $this->assertNotNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells')
        );
    }
}
