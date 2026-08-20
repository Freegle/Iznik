<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\ChatMessage;
use App\Services\Ripple\ReachQueryService;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RippleReplyServiceTest extends TestCase
{
    // Box covering lng [-0.2, 0.0], lat [51.4, 51.6].
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';
    private const INSIDE = [51.5, -0.1];   // [lat, lng]
    private const OUTSIDE = [52.0, 1.0];

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');
    }

    private function service(): RippleReplyService
    {
        return new RippleReplyService(new ReachQueryService());
    }

    private function seedReachedPost(): int
    {
        return $this->seedReachedPostWith(self::POLY, 51.5, -0.1);
    }

    /** As seedReachedPost, with the reach geometry and origin chosen by the caller. */
    private function seedReachedPostWith(string $poly, float $lat, float $lng): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $lat, $lng, $poly, $poly]
        );

        return (int) $message->id;
    }

    /** Returns [ripplingRowId, chatmsgid]. */
    private function seedHeldReply(int $msgid, array $latLng): array
    {
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        // Deliberately NOT reviewrequired — the rippling hold must not rely on it.
        $cm = $this->createTestChatMessage($room, $u1);
        // hold() takes (lat, lng); $latLng is [lat, lng].
        $id = $this->service()->hold($room->id, $cm->id, $msgid, $u1->id, $latLng[0], $latLng[1]);

        return [$id, $cm->id];
    }

    public function test_should_hold_when_post_is_rippling_and_replier_outside_reach(): void
    {
        $msgid = $this->seedReachedPost();
        $svc = $this->service();
        $this->assertTrue($svc->shouldHold($msgid, self::OUTSIDE[0], self::OUTSIDE[1]));
        $this->assertFalse($svc->shouldHold($msgid, self::INSIDE[0], self::INSIDE[1]));
    }

    public function test_should_not_hold_when_post_has_no_reach(): void
    {
        $this->assertFalse($this->service()->shouldHold(999999999, self::OUTSIDE[0], self::OUTSIDE[1]));
    }

    public function test_should_not_hold_with_unknown_location(): void
    {
        $msgid = $this->seedReachedPost();
        $this->assertFalse($this->service()->shouldHold($msgid, null, null));
    }

    /**
     * shouldHold's $band already reaches the rural ring end-to-end, via
     * ReachQueryService::isWithinReach -> isWithinOverflow (see ReachQueryServiceTest for the
     * ring admission tests proper): an email/TrashNothing replier outside the reach but inside
     * their own band's ring must not be held, so email replies behave identically to the web
     * reply gate and to browse.
     */
    public function test_should_not_hold_a_replier_admitted_by_their_rural_ring(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        $msgid = $this->seedReachedPost();
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'overflow_bounds' => json_encode([
                'rural' => ['sparse' => 'POLYGON((0.5 51.9,1.5 51.9,1.5 52.5,0.5 52.5,0.5 51.9))'],
                'bbox' => [0.5, 51.9, 1.5, 52.5],
            ]),
        ]);

        $svc = $this->service();
        // Same point as self::OUTSIDE (52.0, 1.0), which is held with no band / lane off.
        $this->assertFalse($svc->shouldHold($msgid, 52.0, 1.0, 'sparse'));
        $this->assertTrue($svc->shouldHold($msgid, 52.0, 1.0, 'dense'), 'the ring belongs to the band, not the area');
        $this->assertTrue($svc->shouldHold($msgid, 52.0, 1.0, null), 'no band recorded is not eligible');
    }

    public function test_hold_records_held_row_and_blocks_delivery_without_touching_reviewrequired(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
        // Delivery is gated by the rippling row, NOT by reviewrequired (which stays 0).
        $this->assertTrue($this->service()->isDeliveryHeld($cmid));
        $this->assertSame(0, (int) DB::table('chat_messages')->where('id', $cmid)->value('reviewrequired'));
    }

    public function test_release_covered_releases_replies_now_inside_reach(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::INSIDE); // inside the reach box

        $released = $this->service()->releaseCovered($msgid);

        $this->assertSame(1, $released);
        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
        // Gate now allows delivery; reviewrequired never touched.
        $this->assertFalse($this->service()->isDeliveryHeld($cmid));
    }

    public function test_release_covered_keeps_replies_still_outside_held(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $released = $this->service()->releaseCovered($msgid);

        $this->assertSame(0, $released);
        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
        $this->assertTrue($this->service()->isDeliveryHeld($cmid));
    }

    public function test_mark_gone_keeps_delivery_blocked(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $affected = $this->service()->markGone($msgid);

        $this->assertSame(1, $affected);
        $this->assertSame('taken-gone', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
        // taken-gone still blocks delivery (status <> released).
        $this->assertTrue($this->service()->isDeliveryHeld($cmid));
    }

    public function test_mark_gone_tells_the_replier_the_post_is_gone(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $rip = DB::table('rippling_held_replies')->where('id', $rowId)->first();
        $chat = DB::table('chat_rooms')->where('id', $rip->chatid)->first(['user1', 'user2']);
        $replier = (int) $rip->replieruserid;
        $poster = ((int) $chat->user1 === $replier) ? (int) $chat->user2 : (int) $chat->user1;

        $this->service()->markGone($msgid);

        // A System message is posted into the chat, authored by the poster (so the existing
        // chat pipeline notifies the REPLIER), referencing the taken post and queued for
        // delivery — it is NOT the held reply and is not delivery-gated.
        $sys = DB::table('chat_messages')
            ->where('chatid', $rip->chatid)
            ->where('type', ChatMessage::TYPE_SYSTEM)
            ->where('userid', $poster)
            ->first();
        $this->assertNotNull($sys, 'replier is told the post is gone via a System chat message');
        $this->assertSame((int) $msgid, (int) $sys->refmsgid);
        $this->assertStringContainsStringIgnoringCase('taken', $sys->message);
        // Inserted pre-processed so the spam/ban check (on the poster) and chain-holds can't
        // suppress it — it's live immediately and delivered via the chat-notification path.
        $this->assertSame(0, (int) $sys->processingrequired);
        $this->assertSame(1, (int) $sys->processingsuccessful);
        $this->assertNotEquals($cmid, $sys->id, 'the notice is a new message, not the held reply');
    }

    /**
     * The buffer band hugs the reach boundary, so what sets the wait is how far PAST THE
     * EDGE somebody is, not how far the item happens to be. Two repliers the same short
     * distance outside two different reaches are the same kind of near-miss and must wait
     * the same, even when one item is four miles away and the other nearly fifty.
     *
     * This is the case the old measure got backwards. On live rows it charged a replier
     * 0.79 miles outside the boundary 83 minutes because the item was 22.7 miles off,
     * while another 0.68 miles outside waited 35 - the size of the isochrone deciding the
     * wait rather than the person.
     */
    public function test_delay_is_set_by_distance_past_the_edge_not_distance_to_the_item(): void
    {
        config([
            'freegle.ripple.reply_delay.enabled' => true,
            'freegle.ripple.reply_delay.base_minutes' => 15,
            'freegle.ripple.reply_delay.per_mile_minutes' => 3,
            'freegle.ripple.reply_delay.max_minutes' => 180,
        ]);

        // Both reaches end at lng 0.0 on the eastern side, so a replier at lng 0.1 is the
        // same distance past the edge of each. The origins are not: the small reach is
        // centred 0.1 degrees from its edge, the wide one a full degree.
        $small = $this->seedReachedPostWith(self::POLY, 51.5, -0.1);
        $wide = $this->seedReachedPostWith(
            'POLYGON((-2.0 51.4, 0.0 51.4, 0.0 51.6, -2.0 51.6, -2.0 51.4))',
            51.5,
            -1.0
        );

        $justOutside = [51.5, 0.1];
        [$smallRow] = $this->seedHeldReply($small, $justOutside);
        [$wideRow] = $this->seedHeldReply($wide, $justOutside);

        $waitFor = function (int $rowId): float {
            $row = DB::table('rippling_held_replies')->where('id', $rowId)->first();

            return (strtotime($row->dueat) - strtotime($row->created_at)) / 60.0;
        };

        $smallWait = $waitFor($smallRow);
        $wideWait = $waitFor($wideRow);

        // Same near-miss, same wait. A minute of tolerance for the geographic distance
        // being computed per row rather than compared symbolically.
        $this->assertEqualsWithDelta(
            $smallWait,
            $wideWait,
            1.0,
            'the wait comes from the distance past the edge, which is equal here'
        );

        // And it is genuinely the edge distance being used, not a constant: 0.1 degrees of
        // longitude at this latitude is about 4.3 miles, so about 28 minutes.
        $this->assertEqualsWithDelta(15.0 + 3 * 4.3, $smallWait, 3.0);

        // Under the old measure the wide reach's replier would have been charged for the
        // ~47 miles back to the item, so this pins the regression rather than the value.
        $this->assertLessThan(60.0, $wideWait, 'a near-miss is not charged for a distant item');
    }

    public function test_delay_grows_with_distance_past_the_edge_and_is_capped(): void
    {
        config([
            'freegle.ripple.reply_delay.base_minutes' => 15,
            'freegle.ripple.reply_delay.per_mile_minutes' => 3,
            'freegle.ripple.reply_delay.max_minutes' => 180,
        ]);
        $svc = $this->service();

        // On the boundary itself, and anyone inside it, still waits the base delay -
        // locals go first.
        $this->assertSame(15.0, $svc->delayMinutesForMiles(0.0));
        // Sixteen miles beyond the edge: a little after locals, not days.
        $this->assertSame(15.0 + 3 * 16.0, $svc->delayMinutesForMiles(16.0));
        // Two counties away: capped, so no reply is ever invisible for longer than that.
        $this->assertSame(180.0, $svc->delayMinutesForMiles(500.0));
        // Nonsense distance cannot produce a negative delay.
        $this->assertSame(15.0, $svc->delayMinutesForMiles(-5.0));
    }

    public function test_hold_stamps_the_due_time_it_computed(): void
    {
        config(['freegle.ripple.reply_delay.enabled' => true]);
        $msgid = $this->seedReachedPost();
        [$rowId] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $row = DB::table('rippling_held_replies')->where('id', $rowId)->first();
        $this->assertNotNull($row->dueat, 'a hold is a delay, so it has a due time');
        $this->assertGreaterThan($row->created_at, $row->dueat);
    }

    public function test_release_due_delivers_a_reply_the_reach_will_never_cover(): void
    {
        config(['freegle.ripple.reply_delay.enabled' => true]);
        $msgid = $this->seedReachedPost();
        // OUTSIDE is well beyond the reach box and the reach is not growing, so before
        // this existed the only exit was the max-reach backstop, days later.
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        // Not due yet: still held.
        $this->assertSame(0, $this->service()->releaseDue($msgid));
        $this->assertTrue($this->service()->isDeliveryHeld($cmid));

        // Wind the reply back past its due time.
        DB::table('rippling_held_replies')->where('id', $rowId)->update([
            'created_at' => now()->subDay(),
            'dueat' => null,
        ]);

        $this->assertSame(1, $this->service()->releaseDue($msgid));
        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
        $this->assertFalse($this->service()->isDeliveryHeld($cmid));
    }

    public function test_release_due_stamps_a_due_time_on_rows_held_by_the_web_path(): void
    {
        // The Go/web hold path does not compute the delay, so its rows arrive with a NULL
        // due time. The sweep stamps them, which is what keeps the policy in one place.
        config(['freegle.ripple.reply_delay.enabled' => true]);
        $msgid = $this->seedReachedPost();
        [$rowId] = $this->seedHeldReply($msgid, self::OUTSIDE);
        DB::table('rippling_held_replies')->where('id', $rowId)->update(['dueat' => null]);

        $this->service()->releaseDue($msgid);

        $this->assertNotNull(
            DB::table('rippling_held_replies')->where('id', $rowId)->value('dueat'),
            'the sweep fills in what the web path could not compute'
        );
        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_release_due_does_nothing_when_the_delay_is_switched_off(): void
    {
        config(['freegle.ripple.reply_delay.enabled' => false]);
        $msgid = $this->seedReachedPost();
        [$rowId] = $this->seedHeldReply($msgid, self::OUTSIDE);
        DB::table('rippling_held_replies')->where('id', $rowId)->update(['created_at' => now()->subDay()]);

        $this->assertSame(0, $this->service()->releaseDue($msgid));
        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_release_due_is_counted_separately_from_release_on_coverage(): void
    {
        // The whole point of the change is to see how many replies the delay delivers
        // that coverage never would have, so the two exits cannot share one counter.
        config(['freegle.ripple.reply_delay.enabled' => true]);
        DB::table('rippling_event_metrics')
            ->whereIn('event', ['released', 'released_delayed', 'released_covered'])->delete();
        $count = fn ($e) => (int) DB::table('rippling_event_metrics')
            ->where('day', now()->toDateString())->where('event', $e)->value('count');

        $msgid = $this->seedReachedPost();
        [$rowId] = $this->seedHeldReply($msgid, self::OUTSIDE);
        DB::table('rippling_held_replies')->where('id', $rowId)->update(['created_at' => now()->subDay()]);
        $this->service()->releaseDue($msgid);

        $msgid2 = $this->seedReachedPost();
        $this->seedHeldReply($msgid2, self::INSIDE);
        $this->service()->releaseCovered($msgid2);

        $this->assertSame(1, $count('released_delayed'));
        $this->assertSame(1, $count('released_covered'));
        $this->assertSame(2, $count('released'), 'both still count as a release overall');
    }

    public function test_held_reply_state_transitions_are_counted(): void
    {
        // #3 / §15 instrumentation: hold → 'held', release → 'released', markGone → 'taken_gone'.
        DB::table('rippling_event_metrics')->whereIn('event', ['held', 'released', 'taken_gone'])->delete();
        $count = fn ($e) => (int) DB::table('rippling_event_metrics')
            ->where('day', now()->toDateString())->where('event', $e)->value('count');

        $msgid = $this->seedReachedPost();
        $this->seedHeldReply($msgid, self::OUTSIDE);   // hold()
        $this->assertSame(1, $count('held'), 'hold counted');

        $this->service()->releaseAll($msgid);          // release()
        $this->assertSame(1, $count('released'), 'release counted');

        $msgid2 = $this->seedReachedPost();
        $this->seedHeldReply($msgid2, self::OUTSIDE);
        $this->service()->markGone($msgid2);           // taken-gone
        $this->assertSame(1, $count('taken_gone'), 'taken-gone counted');
    }
}
