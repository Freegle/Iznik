<?php

namespace Tests\Unit\Commands\Ripple;

use App\Models\Message;
use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DedupGeometryCommandTest extends TestCase
{
    private const WKT1 = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const WKT2 = 'POLYGON((1.0 52.0, 1.3 52.0, 1.3 52.3, 1.0 52.3, 1.0 52.0))';

    private const WKT3 = 'POLYGON((2.0 53.0, 2.3 53.0, 2.3 53.3, 2.0 53.3, 2.0 53.0))';

    private const CONFIG_KEY_MARK = 'ripple_dedup_geometry_last_msgid';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        DB::table('config')->where('key', self::CONFIG_KEY_MARK)->delete();
    }

    private function seedRow(string $polyWkt, ?string $maxPolyWkt = null): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, max_polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857),"
            . ($maxPolyWkt !== null ? 'ST_GeomFromText(?, 3857)' : 'NULL')
            . ", ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL,
                     'expanding', ?, ?)",
            array_values(array_filter([
                $msgid, $polyWkt, $maxPolyWkt, $polyWkt, now()->subDay(), now()->subDay(),
            ], static fn ($v) => $v !== null))
        );

        return $msgid;
    }

    private function hashOf(string $wkt): string
    {
        return (string) DB::selectOne('SELECT UNHEX(MD5(ST_AsBinary(ST_GeomFromText(?, 3857)))) AS h', [$wkt])->h;
    }

    public function test_fills_polygon_hash_and_shares_the_geom_row_across_identical_bytes(): void
    {
        $a = $this->seedRow(self::WKT1);
        $b = $this->seedRow(self::WKT1); // byte-identical to $a

        $this->artisan('ripple:dedup-geometry')->assertExitCode(0);

        $rowA = DB::table('rippling_reach')->where('msgid', $a)->first();
        $rowB = DB::table('rippling_reach')->where('msgid', $b)->first();
        $this->assertSame($this->hashOf(self::WKT1), (string) $rowA->polygon_hash);
        $this->assertSame((string) $rowA->polygon_hash, (string) $rowB->polygon_hash);
        $this->assertSame(1, DB::table('rippling_reach_geom')->count(), 'identical bytes share one geom row');
    }

    public function test_fills_max_polygon_hash_too_when_present(): void
    {
        $msgid = $this->seedRow(self::WKT1, self::WKT2);

        $this->artisan('ripple:dedup-geometry')->assertExitCode(0);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame($this->hashOf(self::WKT1), (string) $row->polygon_hash);
        $this->assertSame($this->hashOf(self::WKT2), (string) $row->max_polygon_hash);
        $this->assertSame(2, DB::table('rippling_reach_geom')->count());
    }

    public function test_holds_updated_at_still(): void
    {
        $msgid = $this->seedRow(self::WKT1);
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at');

        $this->artisan('ripple:dedup-geometry')->assertExitCode(0);

        $after = DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at');
        $this->assertSame((string) $before, (string) $after);
    }

    public function test_already_hashed_rows_are_left_untouched(): void
    {
        $msgid = $this->seedRow(self::WKT3);
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');
        $hashBefore = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_hash');

        $this->artisan('ripple:dedup-geometry')->assertExitCode(0);

        $this->assertSame(
            (string) $hashBefore,
            (string) DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_hash')
        );
        $this->assertSame(1, DB::table('rippling_reach_geom')->count(), 'no duplicate row was created');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $msgid = $this->seedRow(self::WKT1);

        $this->artisan('ripple:dedup-geometry', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_hash'));
        $this->assertSame(0, DB::table('rippling_reach_geom')->count());
    }

    public function test_mark_resumes_across_bounded_runs(): void
    {
        $a = $this->seedRow(self::WKT1);
        $b = $this->seedRow(self::WKT2);
        $ids = collect([$a, $b])->sort()->values();

        $this->artisan('ripple:dedup-geometry', ['--limit' => 1])->assertExitCode(0);

        $this->assertNotNull(DB::table('rippling_reach')->where('msgid', $ids[0])->value('polygon_hash'));
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $ids[1])->value('polygon_hash'));

        $this->artisan('ripple:dedup-geometry', ['--limit' => 1])->assertExitCode(0);

        $this->assertNotNull(DB::table('rippling_reach')->where('msgid', $ids[1])->value('polygon_hash'));
    }

    public function test_after_option_does_not_move_the_stored_mark(): void
    {
        $a = $this->seedRow(self::WKT1);
        $b = $this->seedRow(self::WKT2);
        $ids = collect([$a, $b])->sort()->values();

        // A --after run targets a range without disturbing the sweep's own place.
        $this->artisan('ripple:dedup-geometry', ['--after' => 0, '--limit' => 1])->assertExitCode(0);

        $mark = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->value('value');
        $this->assertNull($mark, 'an --after run must not advance the resumable mark');
    }
}
