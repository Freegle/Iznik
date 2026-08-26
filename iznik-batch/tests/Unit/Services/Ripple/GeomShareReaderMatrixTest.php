<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\FirstReply\MaxReachService;
use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\ReachQueryService;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesRingIndex;
use Tests\TestCase;

/**
 * The reader matrix for the geometry dedup: ReachQueryService::isWithinReach and
 * MaxReachService::isWithinMaxReach must answer IDENTICALLY across three states of
 * the same geometry - undeduped (hash NULL, blob present), deduped (hash set, blob
 * still present) and drained (hash set, blob gone/sentinel). A pass on the first two
 * states with a failure on the third is exactly what proves whether the COALESCE
 * join actually carries the read, or the code is silently still trusting the blob.
 */
class GeomShareReaderMatrixTest extends TestCase
{
    use FakesRingIndex;

    private const INSIDE = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const INSIDE_LAT = 51.5;

    private const INSIDE_LNG = -0.1;

    private const OUTSIDE_LAT = 55.0;

    private const OUTSIDE_LNG = -3.0;

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        MaxReachService::forgetAvailability();
        MaxReachService::forgetCellsAvailability();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        $this->fakeRingIndex();
    }

    private function seedReachRow(string $state): int
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
            [$msgid, self::INSIDE, self::INSIDE]
        );

        if ($state === 'undeduped') {
            return $msgid;
        }

        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        if ($state === 'drained') {
            DB::statement(
                "UPDATE rippling_reach SET polygon = ST_GeomFromText('POINT(0 0)', 3857) WHERE msgid = ?",
                [$msgid]
            );
        }

        return $msgid;
    }

    private function assertIsWithinReachAgrees(string $state): void
    {
        $msgid = $this->seedReachRow($state);
        $svc = new ReachQueryService();

        $this->assertTrue(
            $svc->isWithinReach($msgid, self::INSIDE_LAT, self::INSIDE_LNG),
            "state={$state}: a point inside the reach must read as within"
        );
        $this->assertFalse(
            $svc->isWithinReach($msgid, self::OUTSIDE_LAT, self::OUTSIDE_LNG),
            "state={$state}: a point outside the reach must read as not within"
        );
    }

    public function test_is_within_reach_agrees_when_undeduped(): void
    {
        $this->assertIsWithinReachAgrees('undeduped');
    }

    public function test_is_within_reach_agrees_when_deduped(): void
    {
        $this->assertIsWithinReachAgrees('deduped');
    }

    public function test_is_within_reach_agrees_when_drained(): void
    {
        $this->assertIsWithinReachAgrees('drained');
    }

    /**
     * The current polygon deliberately does NOT cover the test point - only
     * max_polygon does, so a pass here can only come from the max_polygon read.
     */
    private function seedMaxRow(string $state): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        $tiny = 'POLYGON((9.0 9.0, 9.01 9.0, 9.01 9.01, 9.0 9.01, 9.0 9.0))';

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, max_polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_GeomFromText(?, 3857),
                     ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL,
                     'expanding', NOW(), NOW())",
            [$msgid, $tiny, self::INSIDE, $tiny]
        );

        if ($state === 'undeduped') {
            return $msgid;
        }

        GeomShareService::upsertFromRow($msgid, 'max_polygon');
        GeomShareService::rehashFromRow($msgid, 'max_polygon');

        if ($state === 'drained') {
            DB::table('rippling_reach')->where('msgid', $msgid)->update(['max_polygon' => null]);
        }

        return $msgid;
    }

    private function assertIsWithinMaxReachAgrees(string $state): void
    {
        $msgid = $this->seedMaxRow($state);
        $svc = app(MaxReachService::class);

        $this->assertTrue(
            $svc->isWithinMaxReach($msgid, self::INSIDE_LAT, self::INSIDE_LNG),
            "state={$state}: only max_polygon covers this point"
        );
        $this->assertFalse(
            $svc->isWithinMaxReach($msgid, self::OUTSIDE_LAT, self::OUTSIDE_LNG),
            "state={$state}: outside both polygon and max_polygon"
        );
    }

    public function test_is_within_max_reach_agrees_when_undeduped(): void
    {
        $this->assertIsWithinMaxReachAgrees('undeduped');
    }

    public function test_is_within_max_reach_agrees_when_deduped(): void
    {
        $this->assertIsWithinMaxReachAgrees('deduped');
    }

    public function test_is_within_max_reach_agrees_when_drained(): void
    {
        $this->assertIsWithinMaxReachAgrees('drained');
    }
}
