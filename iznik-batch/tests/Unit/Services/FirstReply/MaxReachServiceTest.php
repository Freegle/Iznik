<?php

namespace Tests\Unit\Services\FirstReply;

use App\Services\FirstReply\MaxReachService;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaxReachServiceTest extends TestCase
{
    // Tick 1: a small box around (51.5, -0.1). Tick 3: a much bigger one that
    // contains it, which is what the eventual reach looks like.
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const INSIDE_TICK1 = [51.5, -0.1];      // [lat, lng]

    private const INSIDE_TICK3_ONLY = [51.9, 0.8];  // outside now, reached later

    private const OUTSIDE_EVERYTHING = [55.0, -3.0];

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachService::forgetAvailability();
        DB::statement('DELETE FROM rippling_reach');
    }

    private function service(): MaxReachService
    {
        return new MaxReachService(app(ReachService::class));
    }

    /** A post rippling at tick 1, with the whole schedule cached as it is in production. */
    private function seedRipplingPost(bool $withSchedule = true): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $schedule = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 200, 'wkt' => self::TICK1],
            ['tick' => 2, 'drive_min' => 15, 'cumulative_users' => 900, 'wkt' => self::TICK3],
            ['tick' => 3, 'drive_min' => 30, 'cumulative_users' => 4000, 'wkt' => self::TICK3],
        ]);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, self::TICK1, self::TICK1, $withSchedule ? $schedule : null]
        );

        return (int) $message->id;
    }

    public function test_populate_stores_the_widest_tick_not_the_current_one(): void
    {
        $msgid = $this->seedRipplingPost();

        $stats = $this->service()->populate();

        $this->assertSame(1, $stats['filled']);
        $this->assertSame(0, $stats['routed'], 'inline WKT means no routing call is needed');

        // Somewhere only the final tick covers is now "will reach", while the
        // current reach is untouched.
        $this->assertTrue($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ));
        $this->assertSame(4000, $this->service()->maxCumulativeUsers($msgid));
    }

    public function test_current_reach_still_counts_as_within_max_reach(): void
    {
        $msgid = $this->seedRipplingPost();
        $this->service()->populate();

        $this->assertTrue($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK1[0], self::INSIDE_TICK1[1]
        ));
    }

    public function test_somewhere_the_post_never_reaches_is_not_within_max_reach(): void
    {
        $msgid = $this->seedRipplingPost();
        $this->service()->populate();

        $this->assertFalse($this->service()->isWithinMaxReach(
            $msgid, self::OUTSIDE_EVERYTHING[0], self::OUTSIDE_EVERYTHING[1]
        ));
    }

    public function test_unpopulated_row_reports_nothing_within_max_reach(): void
    {
        // This is the pre-backfill state, and it must read as "no wider reach
        // known" rather than as "everywhere" or an error: callers fall back to
        // existing behaviour on false.
        $msgid = $this->seedRipplingPost();

        $this->assertFalse($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ));
        $this->assertNull($this->service()->maxCumulativeUsers($msgid));
    }

    public function test_populate_is_idempotent(): void
    {
        $this->seedRipplingPost();

        $this->assertSame(1, $this->service()->populate()['filled']);
        $this->assertSame(0, $this->service()->populate()['scanned'], 'already-filled rows are not re-scanned');
    }

    public function test_post_with_no_reach_row_is_not_within_max_reach(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $this->assertFalse($this->service()->isWithinMaxReach((int) $message->id, 51.5, -0.1));
    }

    public function test_schedule_order_does_not_decide_the_max(): void
    {
        // The widest tick is picked by tick NUMBER, not by array position, so a
        // reordered payload cannot silently narrow every post's eventual reach.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $schedule = json_encode([
            ['tick' => 3, 'drive_min' => 30, 'cumulative_users' => 4000, 'wkt' => self::TICK3],
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 200, 'wkt' => self::TICK1],
        ]);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, self::TICK1, self::TICK1, $schedule]
        );

        $this->service()->populate();

        $this->assertTrue($this->service()->isWithinMaxReach(
            (int) $message->id, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ));
    }
}
