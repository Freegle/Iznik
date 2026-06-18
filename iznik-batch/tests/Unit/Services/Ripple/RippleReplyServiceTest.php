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
        DB::statement('DELETE FROM chat_messages_rippling');
        DB::statement('DELETE FROM messages_reach');
    }

    private function service(): RippleReplyService
    {
        return new RippleReplyService(new ReachQueryService());
    }

    private function seedReachedPost(): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        DB::statement(
            "INSERT INTO messages_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::POLY]
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

    public function test_hold_records_held_row_and_blocks_delivery_without_touching_reviewrequired(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $this->assertSame('held', DB::table('chat_messages_rippling')->where('id', $rowId)->value('status'));
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
        $this->assertSame('released', DB::table('chat_messages_rippling')->where('id', $rowId)->value('status'));
        // Gate now allows delivery; reviewrequired never touched.
        $this->assertFalse($this->service()->isDeliveryHeld($cmid));
    }

    public function test_release_covered_keeps_replies_still_outside_held(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $released = $this->service()->releaseCovered($msgid);

        $this->assertSame(0, $released);
        $this->assertSame('held', DB::table('chat_messages_rippling')->where('id', $rowId)->value('status'));
        $this->assertTrue($this->service()->isDeliveryHeld($cmid));
    }

    public function test_mark_gone_keeps_delivery_blocked(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $affected = $this->service()->markGone($msgid);

        $this->assertSame(1, $affected);
        $this->assertSame('taken-gone', DB::table('chat_messages_rippling')->where('id', $rowId)->value('status'));
        // taken-gone still blocks delivery (status <> released).
        $this->assertTrue($this->service()->isDeliveryHeld($cmid));
    }

    public function test_mark_gone_tells_the_replier_the_post_is_gone(): void
    {
        $msgid = $this->seedReachedPost();
        [$rowId, $cmid] = $this->seedHeldReply($msgid, self::OUTSIDE);

        $rip = DB::table('chat_messages_rippling')->where('id', $rowId)->first();
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

    public function test_held_reply_state_transitions_are_counted(): void
    {
        // #3 / §15 instrumentation: hold → 'held', release → 'released', markGone → 'taken_gone'.
        DB::table('ripple_event_metrics')->whereIn('event', ['held', 'released', 'taken_gone'])->delete();
        $count = fn ($e) => (int) DB::table('ripple_event_metrics')
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
