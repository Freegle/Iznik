<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ripple:shrink-overflow-bounds rewrites stored ring WKT at 4dp instead of 14
 * significant digits. It had no test; these cover the part that interacts with
 * the cell-set columns (plans/2026-08-24-rippling-reach-raster-storage.md),
 * plus the invariants the command's own docblock says make the rewrite safe.
 */
class ShrinkOverflowBoundsCommandTest extends TestCase
{
    private const REACH = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const CONFIG_KEY_MARK = 'ripple_shrink_overflow_bounds_last_msgid';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
        DB::table('config')->where('key', self::CONFIG_KEY_MARK)->delete();
    }

    /**
     * A ring written the old way: every vertex at PHP's 14 significant digits,
     * and enough of them that rewriting sheds well over the --min-saving floor.
     * The vertices still sit on the 0.0003-degree lattice, as real traced rings
     * do - the excess digits are float rendering, not information.
     */
    private function longPrecisionRing(): string
    {
        $cell = 0.0003;
        $pts = [];
        for ($i = 0; $i < 120; $i++) {
            $pts[] = sprintf('%.12f %.12f', -1.0 + $i * $cell, 51.0);
        }
        for ($i = 120; $i > 0; $i--) {
            $pts[] = sprintf('%.12f %.12f', -1.0 + $i * $cell, 51.0 + 40 * $cell);
        }
        $pts[] = sprintf('%.12f %.12f', -1.0, 51.0);

        return 'POLYGON((' . implode(', ', $pts) . '))';
    }

    private function seedRingedRow(): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        $bounds = ['rural' => ['sparse' => $this->longPrecisionRing()]];

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, overflow_bounds, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), ?,
                     NOW(), 'drive', 1, 3, 4000, 30, NULL, 'done', ?, ?)",
            [$msgid, self::REACH, self::REACH, json_encode($bounds), now()->subDays(2), now()->subDays(2)]
        );

        return $msgid;
    }

    private function row(int $msgid): object
    {
        return DB::table('rippling_reach')->where('msgid', $msgid)->first();
    }

    public function test_it_rewrites_the_ring_at_four_decimals(): void
    {
        $msgid = $this->seedRingedRow();
        $before = strlen((string) $this->row($msgid)->overflow_bounds);

        $this->artisan('ripple:shrink-overflow-bounds')
            ->expectsOutputToContain('Rewrote 1 row(s)')
            ->assertExitCode(0);

        $after = (string) $this->row($msgid)->overflow_bounds;
        $this->assertLessThan($before, strlen($after), 'the rewritten ring must be smaller');
        $this->assertStringNotContainsString(
            '.000000000000',
            $after,
            'the 14-significant-digit rendering must be gone'
        );
    }

    public function test_it_clears_the_ring_cells_so_they_cannot_describe_the_old_shape(): void
    {
        // The two columns must never disagree. The drift a rewrite could cause
        // is tiny - no vertex moves more than 5.3m against a 33m cell - but
        // "tiny" is not a property anything checks, whereas NULL means "use the
        // rings" everywhere.
        $msgid = $this->seedRingedRow();

        $cellSets = app(CellSetService::class);
        $before = (string) $this->row($msgid)->overflow_bounds;
        $bounds = json_decode($before, true);
        $cells = $cellSets->rasterize($bounds['rural']['sparse']);
        $this->assertNotNull($cells, 'the real rasteriser must answer for this test to mean anything');
        DB::table('rippling_reach')->where('msgid', $msgid)
            ->update(['overflow_cells' => json_encode(['rural' => ['sparse' => base64_encode($cells)]])]);

        // --min-saving=0 so this test is about the CLEARING, not about whether
        // the fixture happens to shed enough bytes to be worth rewriting.
        // The output assertion is the precondition: if the row was not
        // rewritten at all then "the cells were not cleared" is the wrong
        // diagnosis, and this fails saying so rather than blaming the clearing.
        $this->artisan('ripple:shrink-overflow-bounds --min-saving=0')
            ->expectsOutputToContain('Rewrote 1 row(s)')
            ->assertExitCode(0);

        $row = $this->row($msgid);
        $this->assertNotSame(
            $before,
            (string) $row->overflow_bounds,
            'precondition: the command must actually have rewritten this row'
        );
        $this->assertNull($row->overflow_cells, 'a rewritten ring must leave no cell set behind');
        $this->assertNotNull($row->overflow_bounds, 'and the rings themselves must still be there');
        $this->assertSame(
            1,
            (int) $row->has_overflow,
            'the generated indexed flag the ring index hangs off must survive'
        );
    }

    public function test_it_holds_updated_at_still(): void
    {
        // A bulk reach backfill once generated 38k+ notification emails in a
        // morning by bumping this column.
        $msgid = $this->seedRingedRow();
        $before = $this->row($msgid)->updated_at;

        $this->artisan('ripple:shrink-overflow-bounds')->assertExitCode(0);

        $this->assertSame(
            (string) $before,
            (string) $this->row($msgid)->updated_at,
            'updated_at must be held still - the reach mailer and the spatial delta poll watch it'
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $msgid = $this->seedRingedRow();
        $before = (string) $this->row($msgid)->overflow_bounds;

        $this->artisan('ripple:shrink-overflow-bounds --dry-run')->assertExitCode(0);

        $this->assertSame($before, (string) $this->row($msgid)->overflow_bounds, 'a dry run must not rewrite');
    }
}
