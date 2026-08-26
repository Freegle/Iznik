<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\ReachQueryService;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesRingIndex;
use Tests\TestCase;

/**
 * RippleReplyService::milesOutsideReach is private, reached here (per house
 * precedent - RippleReplyServiceTest has no reflection anywhere) through the
 * public hold() -> dueat stamp it decides, exactly as
 * test_delay_is_set_by_distance_past_the_edge_not_distance_to_the_item does.
 *
 * The proof needed for the geometry dedup: the SAME near-miss produces the
 * SAME wait whether the reach geometry is undeduped, deduped (hash set, blob
 * still present) or drained (blob replaced by the sentinel, hash set). A bug
 * that silently fell back to the sentinel POINT(0 0) would not produce a
 * slightly-wrong answer - it would measure thousands of miles to the Gulf of
 * Guinea and hit the 180-minute cap, which is why "not capped" is itself part
 * of the proof, not just "the three agree with each other".
 */
class GeomShareRippleReplyMatrixTest extends TestCase
{
    use FakesRingIndex;

    // Box covering lng [-0.2, 0.0], lat [51.4, 51.6] - same fixture as
    // RippleReplyServiceTest, so the ~4.3-mile/~28-minute expectation carries over.
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const JUST_OUTSIDE = [51.5, 0.1]; // [lat, lng]

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        $this->fakeRingIndex();
        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        config([
            'freegle.ripple.reply_delay.enabled' => true,
            'freegle.ripple.reply_delay.base_minutes' => 15,
            'freegle.ripple.reply_delay.per_mile_minutes' => 3,
            'freegle.ripple.reply_delay.max_minutes' => 180,
        ]);
    }

    private function service(): RippleReplyService
    {
        return new RippleReplyService(new ReachQueryService());
    }

    private function seedReach(string $state): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$msgid, self::POLY, self::POLY]
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

    /** Hold a reply from JUST_OUTSIDE and return the minutes between created_at and dueat. */
    private function waitMinutes(int $msgid): float
    {
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1);

        $rowId = $this->service()->hold($room->id, $cm->id, $msgid, $u1->id, self::JUST_OUTSIDE[0], self::JUST_OUTSIDE[1]);

        $row = DB::table('rippling_held_replies')->where('id', $rowId)->first();
        $this->assertNotNull($row->dueat, 'a hold is a delay, so it must have a due time in every state');

        return (strtotime($row->dueat) - strtotime($row->created_at)) / 60.0;
    }

    public function test_wait_is_identical_across_dedup_states(): void
    {
        $undeduped = $this->waitMinutes($this->seedReach('undeduped'));
        $deduped = $this->waitMinutes($this->seedReach('deduped'));
        $drained = $this->waitMinutes($this->seedReach('drained'));

        // Same tolerance as RippleReplyServiceTest's own cross-post comparison - the
        // geographic distance is computed per row, not compared symbolically.
        $this->assertEqualsWithDelta($undeduped, $deduped, 1.0, 'deduped must wait the same as undeduped');
        $this->assertEqualsWithDelta($undeduped, $drained, 1.0, 'drained must wait the same as undeduped');

        // 0.1 degrees of longitude at this latitude is about 4.3 miles, so about 28
        // minutes - the real value, not a placeholder.
        $this->assertEqualsWithDelta(15.0 + 3 * 4.3, $undeduped, 3.0);

        // The proof that matters for the dedup specifically: none of the three is
        // capped at max_minutes. A join that silently fell back to the drain sentinel
        // POINT(0 0) would measure thousands of miles to the Gulf of Guinea and hit
        // the 180-minute cap - a completely different, unmissable number.
        $this->assertLessThan(60.0, $undeduped);
        $this->assertLessThan(60.0, $deduped);
        $this->assertLessThan(60.0, $drained, 'the drained state must read the shared geometry, not the sentinel');
    }
}
