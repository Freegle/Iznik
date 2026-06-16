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
        DB::statement('DELETE FROM chat_messages_rippling');
        DB::statement('DELETE FROM messages_reach');
    }

    /** @return array{0:int,1:int} [ripplingRowId, chatmsgid] — a held reply INSIDE the reach. */
    private function seedHeldInsideReach(): array
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

        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1, ['reviewrequired' => 1]);
        $rowId = app(RippleReplyService::class)->hold($room->id, $cm->id, $message->id, $u1->id, 51.5, -0.1);

        return [$rowId, $cm->id];
    }

    public function test_disabled_flag_is_noop(): void
    {
        config(['freegle.ripple.hold_replies' => false]);
        [$rowId] = $this->seedHeldInsideReach();

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('held', DB::table('chat_messages_rippling')->where('id', $rowId)->value('status'));
    }

    public function test_releases_covered_replies_when_enabled(): void
    {
        config(['freegle.ripple.hold_replies' => true]);
        [$rowId, $cmid] = $this->seedHeldInsideReach();

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('released', DB::table('chat_messages_rippling')->where('id', $rowId)->value('status'));
        $this->assertSame(0, (int) DB::table('chat_messages')->where('id', $cmid)->value('reviewrequired'));
    }
}
