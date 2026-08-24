<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifyGeometryDedupCommandTest extends TestCase
{
    private const WKT1 = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const WKT2 = 'POLYGON((1.0 52.0, 1.3 52.0, 1.3 52.3, 1.0 52.3, 1.0 52.0))';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
    }

    private function seedRow(string $polyWkt): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(),
                     'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$msgid, $polyWkt, $polyWkt]
        );

        return $msgid;
    }

    public function test_healthy_backfilled_rows_pass(): void
    {
        $msgid = $this->seedRow(self::WKT1);
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        $this->artisan('ripple:verify-geometry-dedup')->assertExitCode(0);
    }

    public function test_a_dangling_hash_fails_and_is_reported(): void
    {
        $msgid = $this->seedRow(self::WKT1);
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');
        $hash = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_hash');

        // The FK RESTRICT is exactly what makes this unreachable through the
        // application - simulate the state it guards against (e.g. a hash written
        // before the constraint existed) by dropping the geom row under it.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('rippling_reach_geom')->where('hash', $hash)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->artisan('ripple:verify-geometry-dedup')
            ->expectsOutputToContain('dangling')
            ->assertExitCode(1);
    }

    public function test_a_blob_changed_without_its_hash_is_reported_as_blob_bad(): void
    {
        $msgid = $this->seedRow(self::WKT1);
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        // Simulate a write site that changed the blob but did not update the hash -
        // exactly the class of bug this checker exists to catch.
        DB::statement('UPDATE rippling_reach SET polygon = ST_GeomFromText(?, 3857) WHERE msgid = ?', [self::WKT2, $msgid]);

        $this->artisan('ripple:verify-geometry-dedup')
            ->expectsOutputToContain('blob_bad')
            ->assertExitCode(1);
    }

    public function test_a_range_with_no_hashed_rows_fails_as_compared_nothing(): void
    {
        $this->seedRow(self::WKT1); // no hash set at all

        $this->artisan('ripple:verify-geometry-dedup')
            ->expectsOutputToContain('Compared nothing')
            ->assertExitCode(1);
    }
}
