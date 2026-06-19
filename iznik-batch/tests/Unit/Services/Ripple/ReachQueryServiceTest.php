<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachQueryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReachQueryServiceTest extends TestCase
{
    // A box covering lng [-0.2, 0.0], lat [51.4, 51.6].
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
    }

    private function seedReach(): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lamp (London)',
            'textbody' => 'A lamp.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::POLY]
        );

        return (int) $message->id;
    }

    public function test_point_inside_reach_is_within(): void
    {
        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($this->seedReach(), 51.5, -0.1));
    }

    public function test_point_outside_reach_is_not_within(): void
    {
        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($this->seedReach(), 52.0, 1.0));
    }

    public function test_missing_reach_row_is_not_within(): void
    {
        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach(999999999, 51.5, -0.1));
    }

    public function test_any_location_covered_returns_true_if_one_inside(): void
    {
        $msgid = $this->seedReach();
        $svc = new ReachQueryService();
        // First location outside, second inside → eligible (multiple viewer locations).
        $this->assertTrue($svc->isWithinReachAny($msgid, [[52.0, 1.0], [51.5, -0.1]]));
        // All outside → not eligible.
        $this->assertFalse($svc->isWithinReachAny($msgid, [[52.0, 1.0], [40.0, 0.0]]));
    }
}
