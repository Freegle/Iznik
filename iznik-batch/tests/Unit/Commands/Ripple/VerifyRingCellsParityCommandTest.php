<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\LegacyGeometry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The property that matters most in a verification tool is that IT CAN FAIL.
 * One that passes on genuinely wrong data is worse than none, because it gets
 * quoted as proof - and this one is quoted as proof for an irreversible DDL.
 *
 * So most of these tests plant a specific corruption and assert it is caught:
 * a ring with no grid, a grid with no ring, and a grid describing somewhere
 * else entirely. The honest-row case is here too, because a checker that
 * fails on correct data is just as useless.
 *
 * Real rasterise calls, like the other cell-set tests: this command exists to
 * compare stored bytes against stored geometry, so faking either half would
 * prove nothing about whether the comparison works.
 */
class VerifyRingCellsParityCommandTest extends TestCase
{
    private const REACH = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    // SMALL ON PURPOSE. The lattice is 0.0003 degrees, so area translates
    // directly into work: the first draft of this file used a 3 x 2 degree ring,
    // which is ~67 million cells, and the command's old decode()-based
    // comparison died on it with "Allowed memory size of 2147483648 bytes
    // exhausted". The comparison no longer decodes, but there is still no
    // reason for a unit test to rasterise a county. 0.06 degrees square is
    // ~200 x 200 cells and exercises exactly the same paths.
    private const SPARSE_RING = 'POLYGON((-0.10 51.50, -0.04 51.50, -0.04 51.56, -0.10 51.56, -0.10 51.50))';

    // A ring of the same size ADJACENT to SPARSE_RING rather than far away.
    // Adjacency keeps the union of the two extents tight, so the probe lattice
    // the command lays over it puts plenty of points inside each ring - a
    // distant pair would spread the same probes thinly and could disagree at
    // only a handful of points.
    private const ELSEWHERE = 'POLYGON((-0.02 51.50, 0.04 51.50, 0.04 51.56, -0.02 51.56, -0.02 51.50))';

    protected function setUp(): void
    {
        parent::setUp();
        LegacyGeometry::reset();
        DB::statement('DELETE FROM rippling_reach');
    }

    protected function tearDown(): void
    {
        LegacyGeometry::reset();
        parent::tearDown();
    }

    /**
     * Seed one ringed row. $cellsWkt is what the STORED GRID is rasterised
     * from, so passing something other than $ringWkt produces a row whose grid
     * and ring genuinely disagree. $omitGrid leaves the grid out altogether.
     */
    private function seedRow(string $ringWkt, ?string $cellsWkt = null, bool $omitGrid = false, array $extraCells = []): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        $bounds = ['rural' => ['sparse' => $ringWkt]];

        $cells = [];
        if (!$omitGrid) {
            $bytes = app(CellSetService::class)->rasterize($cellsWkt ?? $ringWkt);
            if ($bytes === null) {
                $this->fail('the spatial rasteriser is unreachable, so this test cannot prove anything');
            }
            $cells = ['rural' => ['sparse' => base64_encode($bytes)]];
        }
        foreach ($extraCells as $lane => $bands) {
            $cells[$lane] = $bands;
        }

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, overflow_bounds, overflow_cells, arrival, mode,
                tick, total_ticks, total_freeglers, max_drive_min, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     ?, ?, NOW(), 'drive', 1, 3, 4000, 30, 'done', NOW(), NOW())",
            [$msgid, self::REACH, self::REACH, json_encode($bounds), json_encode($cells)]
        );

        return $msgid;
    }

    /** An honest row must pass, or the command is useless as a gate. */
    public function test_a_grid_matching_its_ring_passes(): void
    {
        $this->seedRow(self::SPARSE_RING);

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('No failures')
            ->assertExitCode(0);
    }

    /**
     * THE failure this command was written for. A lane carrying ring WKT with
     * no stored grid admits nobody once overflow_bounds is dropped - and it is
     * invisible to everything else: the backfill's compare-and-swap only
     * revisits rows where overflow_cells IS NULL, and the drop migration's
     * guard tests that same condition, so a row with SOME grids passes both.
     */
    public function test_a_ring_with_no_stored_grid_is_a_failure(): void
    {
        $this->seedRow(self::SPARSE_RING, omitGrid: true);

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('admits nobody')
            ->assertExitCode(1);
    }

    /** A grid with no ring behind it would admit people no ring ever covered. */
    public function test_a_stored_grid_with_no_ring_behind_it_is_a_failure(): void
    {
        $this->seedRow(self::SPARSE_RING, extraCells: [
            'cluster' => ['w1' => base64_encode('CCS1' . str_repeat("\0", 16))],
        ]);

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('no ring WKT behind it')
            ->assertExitCode(1);
    }

    /**
     * A grid describing somewhere else must be caught by AREA, not merely by
     * byte inequality - the command compares the covered sets, so this proves
     * the comparison is real rather than a checksum.
     */
    public function test_a_grid_describing_a_different_area_is_a_failure(): void
    {
        $this->seedRow(self::SPARSE_RING, cellsWkt: self::ELSEWHERE);

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('different area')
            ->assertExitCode(1);
    }

    /** Unreadable stored bytes are a failure, not something to skip past. */
    public function test_an_undecodable_grid_is_a_failure(): void
    {
        $msgid = $this->seedRow(self::SPARSE_RING);
        DB::statement(
            'UPDATE rippling_reach SET overflow_cells = ? WHERE msgid = ?',
            [json_encode(['rural' => ['sparse' => base64_encode('not a cell set at all')]]), $msgid]
        );

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->assertExitCode(1);
    }

    /**
     * Nothing to compare is a FAILURE, not a vacuous pass. An empty sample and
     * a clean sample print almost the same thing otherwise, and this output is
     * quoted as evidence for a drop that cannot be undone.
     */
    public function test_no_comparable_rows_is_a_failure(): void
    {
        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('nothing to compare')
            ->assertExitCode(1);
    }

    /** Post-drop there is nothing to compare against, and it must say so. */
    public function test_refuses_once_the_ring_wkt_is_gone(): void
    {
        $this->seedRow(self::SPARSE_RING);
        LegacyGeometry::fake(overflow: false);

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1])
            ->expectsOutputToContain('has been dropped')
            ->assertExitCode(1);
    }

    /** The report must be machine-readable, because its numbers get quoted. */
    public function test_writes_a_json_report(): void
    {
        $this->seedRow(self::SPARSE_RING);
        $path = tempnam(sys_get_temp_dir(), 'ringparity') ?: '/tmp/ringparity-test.json';

        $this->artisan('ripple:verify-ring-cells-parity', ['--limit' => 1, '--json' => $path])
            ->assertExitCode(0);

        $report = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $this->assertIsArray($report);
        // A report claiming zero failures over zero rings would be worthless,
        // so the ring count is part of what makes the pass meaningful.
        $this->assertGreaterThan(0, $report['summary']['rings'], 'a report over no rings proves nothing');
        $this->assertSame(0, $report['summary']['lostRings']);
    }
}
