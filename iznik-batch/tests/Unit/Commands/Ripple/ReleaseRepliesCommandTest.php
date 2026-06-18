<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleaseRepliesCommandTest extends TestCase
{
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');
    }

    /** @return array{0:int,1:int} [ripplingRowId, chatmsgid] — a held reply INSIDE the reach. */
    private function seedHeldInsideReach(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::POLY]
        );

        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1);
        $rowId = app(RippleReplyService::class)->hold($room->id, $cm->id, $message->id, $u1->id, 51.5, -0.1);

        return [$rowId, $cm->id, (int) $message->id];
    }

    /** A held reply for a post with NO reach row (msgid only). */
    private function seedHeldNoReach(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1);
        $rowId = app(RippleReplyService::class)->hold($room->id, $cm->id, $message->id, $u1->id, 51.5, -0.1);

        return [$rowId, (int) $message->id];
    }

    public function test_releases_covered_replies(): void
    {
        [$rowId] = $this->seedHeldInsideReach();

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_no_reach_but_post_still_active_does_not_mark_gone(): void
    {
        // Regression: a post transiently absent from messages_spatial (reach row gone)
        // but NOT actually taken/withdrawn must not have its replies wrongly marked gone.
        [$rowId] = $this->seedHeldNoReach(); // message is live, no outcome, no reach row

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_no_reach_and_post_taken_marks_gone(): void
    {
        [$rowId, $msgid] = $this->seedHeldNoReach();
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('taken-gone', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }
}
