<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\ChatMessage;
use App\Services\FirstReply\MaxReachService;
use App\Services\Ripple\ReachQueryService;
use App\Services\Ripple\RippleReplyService;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The first-reply passthrough: a post with no replies does not hold its first
 * one, provided the replier is inside the reach the post will eventually have.
 */
class FirstReplyPassthroughTest extends TestCase
{
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const INSIDE_NOW = [51.5, -0.1];

    private const REACHED_LATER = [51.9, 0.8];

    private const NEVER_REACHED = [55.0, -3.0];

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachService::forgetAvailability();
        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');

        config([
            'freegle.firstreply.enabled' => true,
            // Whole-network arm: the rollout percentage is exercised separately.
            'freegle.firstreply.rollout_percent' => 100,
            'freegle.firstreply.passthrough.enabled' => true,
            'freegle.firstreply.passthrough.max_existing_repliers' => 1,
        ]);
    }

    private function service(): RippleReplyService
    {
        return new RippleReplyService(new ReachQueryService(), new MaxReachService(app(ReachService::class)));
    }

    /** @return array{0:int, 1:\App\Models\User} the post id and its poster */
    private function seedRipplingPost(): array
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        $schedule = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 200, 'wkt' => self::TICK1],
            ['tick' => 3, 'drive_min' => 30, 'cumulative_users' => 4000, 'wkt' => self::TICK3],
        ]);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, self::TICK1, self::TICK1, $schedule]
        );

        app(MaxReachService::class)->populate();

        return [(int) $message->id, $poster];
    }

    private function addReplier(int $msgid, \App\Models\User $poster): void
    {
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($replier, $poster);
        $this->createTestChatMessage($room, $replier, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msgid,
        ]);
    }

    public function test_first_reply_from_somewhere_the_post_will_reach_is_not_held(): void
    {
        [$msgid] = $this->seedRipplingPost();

        $this->assertFalse(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1]),
            'the replier is outside the reach TODAY but inside the reach the post ends up with, '
            . 'and the post has nothing at all, so holding only makes the poster wait'
        );
    }

    public function test_second_reply_from_the_same_place_is_held_as_before(): void
    {
        [$msgid, $poster] = $this->seedRipplingPost();
        $this->addReplier($msgid, $poster);

        $this->assertTrue(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1]),
            'once a post has a reply, local-first ordering is worth the delay again'
        );
    }

    public function test_reply_from_somewhere_the_post_never_reaches_is_still_held(): void
    {
        [$msgid] = $this->seedRipplingPost();

        $this->assertTrue(
            $this->service()->shouldHold($msgid, self::NEVER_REACHED[0], self::NEVER_REACHED[1])
        );
    }

    public function test_reply_from_inside_the_current_reach_was_never_held_anyway(): void
    {
        [$msgid] = $this->seedRipplingPost();

        $this->assertFalse(
            $this->service()->shouldHold($msgid, self::INSIDE_NOW[0], self::INSIDE_NOW[1])
        );
    }

    public function test_switched_off_means_the_old_behaviour_exactly(): void
    {
        [$msgid] = $this->seedRipplingPost();
        config(['freegle.firstreply.passthrough.enabled' => false]);

        $this->assertTrue(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1])
        );
    }

    public function test_master_switch_off_disables_the_passthrough_too(): void
    {
        [$msgid] = $this->seedRipplingPost();
        config(['freegle.firstreply.enabled' => false]);

        $this->assertTrue(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1])
        );
    }

    public function test_poster_talking_on_their_own_post_does_not_use_up_the_passthrough(): void
    {
        [$msgid, $poster] = $this->seedRipplingPost();

        // The poster adding to their own post is not a reply, and must not make
        // the post look answered.
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $other);
        $this->createTestChatMessage($room, $poster, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msgid,
        ]);

        $this->assertFalse(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1])
        );
    }

    public function test_unpopulated_max_reach_leaves_the_hold_in_place(): void
    {
        // Deploying ahead of the backfill must change nothing.
        [$msgid] = $this->seedRipplingPost();
        DB::statement('UPDATE rippling_reach SET max_polygon = NULL WHERE msgid = ?', [$msgid]);

        $this->assertTrue(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1])
        );
    }
}
