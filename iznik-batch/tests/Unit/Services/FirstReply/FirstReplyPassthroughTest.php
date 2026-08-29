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
    use \Tests\Support\SeedsReachCells;

    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const INSIDE_NOW = [51.5, -0.1];

    private const REACHED_LATER = [51.9, 0.8];

    private const NEVER_REACHED = [55.0, -3.0];

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');

        config([
            'freegle.firstreply.enabled' => true,
            // Whole-network arm: the rollout percentage is exercised separately.
            'freegle.firstreply.rollout_percent' => 100,
            'freegle.firstreply.passthrough.enabled' => true,
            'freegle.firstreply.passthrough.max_existing_repliers' => 1,
        ]);

        // The routing server, faked by POINT: the label admits everywhere the
        // post will eventually reach and refuses NEVER_REACHED. The gate is
        // under test, not the geometry - that is proven routing-side.
        // Http::fake merges first-stub-wins, so tests needing a different
        // answer set $this->verdictOverride instead of re-faking.
        $this->verdictOverride = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (!str_contains($request->url(), 'reach-eval')) {
                return null;
            }
            $lat = (float) ($request['lat'] ?? 0);
            $lng = (float) ($request['lng'] ?? 0);
            if ($this->verdictOverride !== null) {
                $verdict = $this->verdictOverride;
            } elseif (($request['budget'] ?? '') === 'max') {
                // The eventual reach: everywhere except NEVER_REACHED.
                $verdict = abs($lat - self::NEVER_REACHED[0]) < 0.01 ? 'out' : 'in';
            } else {
                // The current reach: only INSIDE_NOW.
                $in = abs($lat - self::INSIDE_NOW[0]) < 0.01 && abs($lng - self::INSIDE_NOW[1]) < 0.01;
                $verdict = $in ? 'in' : 'out';
            }
            $results = array_map(
                fn ($id) => ['msgid' => (int) $id, 'verdict' => $verdict],
                $request['msgids'] ?? []
            );

            return \Illuminate\Support\Facades\Http::response(['results' => $results]);
        });
    }

    /** When set, every reach-eval answer uses this verdict. */
    private ?string $verdictOverride = null;

    private function service(): RippleReplyService
    {
        return new RippleReplyService(new ReachQueryService(), app(MaxReachService::class));
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
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor(self::TICK1), self::TICK1, $schedule]
        );
        DB::table('rippling_reach')->where('msgid', $message->id)->update(['reach_labels' => 'label-bytes']);

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

    public function test_no_stored_label_leaves_the_hold_in_place(): void
    {
        // A post whose label has not been stored yet must change nothing:
        // no verdict means "no wider reach known", and the hold stands.
        [$msgid] = $this->seedRipplingPost();
        $this->verdictOverride = 'nolabels';

        $this->assertTrue(
            $this->service()->shouldHold($msgid, self::REACHED_LATER[0], self::REACHED_LATER[1])
        );
    }
}
