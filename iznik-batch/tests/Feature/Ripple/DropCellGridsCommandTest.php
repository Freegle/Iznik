<?php

namespace Tests\Feature\Ripple;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ripple:drop-cell-grids drains ONLY the max-reach grid, and ONLY for rows
 * whose stored label decides their reach: the current-reach grid stays (it is
 * what the spatial server's reach containment index is built from), and
 * unlabelled rows keep everything.
 */
class DropCellGridsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
    }

    /** A real message row (rippling_reach.msgid is a foreign key) plus its reach row. */
    private function seedRow(?string $labels): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $user->id,
            'subject' => 'OFFER: drain', 'textbody' => 'x', 'source' => 'Platform',
            'date' => now()->subDay(), 'arrival' => now()->subDay(), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, reach_labels, polygon_cells, max_polygon_cells, outer_bound,
                arrival, mode, tick, total_ticks, total_freeglers, max_drive_min, schedule,
                next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, 'cur-cells', 'max-cells',
                     ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857),
                     NOW(), 'drive', 1, 3, 0, 45, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $labels]
        );

        return (int) $message->id;
    }

    public function test_drains_only_the_max_grid_of_labelled_rows(): void
    {
        $labelled = $this->seedRow('label-bytes');
        $unlabelled = $this->seedRow(null);

        $this->artisan('ripple:drop-cell-grids', ['--sleep-ms' => 0])->assertSuccessful();

        $l = DB::table('rippling_reach')->where('msgid', $labelled)->first();
        $this->assertNull($l->max_polygon_cells, 'the label decides the eventual reach; the max grid is dead weight');
        $this->assertNotNull($l->polygon_cells, 'the current-reach grid feeds the spatial index and must stay');

        $u = DB::table('rippling_reach')->where('msgid', $unlabelled)->first();
        $this->assertNotNull($u->max_polygon_cells, 'an unlabelled row keeps its cells - they are its only record');
        $this->assertNotNull($u->polygon_cells);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $labelled = $this->seedRow('label-bytes');

        $this->artisan('ripple:drop-cell-grids', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(
            DB::table('rippling_reach')->where('msgid', $labelled)->value('max_polygon_cells')
        );
    }
}
