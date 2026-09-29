<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\ChatProcessService;
use App\Services\Lockdown\LockdownHoldsService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ChatProcessService::processIncoming under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.11). While chat is held, member-to-member
 * messages wait unprocessed; once lifted, everything held goes through the ordinary checks
 * straight away. The ordinary (no lockdown) behaviour is covered by ChatProcessServiceTest.
 */
class ChatProcessServiceLockdownTest extends TestCase
{
    private ChatProcessService $service;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_holds')->delete();
        DB::table('spam_users')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->service = new ChatProcessService(null, $this->lockdown);
    }

    private function holdFor(int $chatMessageId): ?object
    {
        return DB::table('lockdown_holds')
            ->where('kind', LockdownHoldsService::KIND_CHAT)
            ->where('refid', $chatMessageId)
            ->first();
    }

    private function hold(int $chatMessageId, int $userId): void
    {
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_CHAT,
            'refid' => $chatMessageId,
            'userid' => $userId,
            'created' => now(),
        ]);
    }

    public function test_held_chat_leaves_user2user_message_queued(): void
    {
        $this->lockdown->press(null, 'wave');

        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, ['processingrequired' => 1, 'processingsuccessful' => 0]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->processingrequired, 'a held member-to-member message is not processed');
        $this->assertEquals(0, $updated->processingsuccessful);
    }

    public function test_held_chat_still_processes_user2mod_chat(): void
    {
        $this->lockdown->press(null, 'wave');

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $room = $this->createTestChatRoom($member, $mod, ['chattype' => ChatRoom::TYPE_USER2MOD]);
        $msg = $this->createTestChatMessage($room, $member, [
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired, 'chat with the volunteers keeps flowing');
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    public function test_lifting_releases_everything_held_in_one_run_oldest_first(): void
    {
        $this->lockdown->press(null, 'wave');

        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);

        // More than two batches, so the release has to go round more than once in one run.
        $count = LockdownHoldsService::RELEASE_BATCH_SIZE * 2 + 50;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $msg = $this->createTestChatMessage($room, $replier, [
                'message' => "Is this still available $i",
                'processingrequired' => 1, 'processingsuccessful' => 0,
            ]);
            $this->hold($msg->id, $replier->id);
            $ids[] = $msg->id;
        }

        $this->lockdown->setSurfaces(['chat' => false], null);

        $this->service->processIncoming();

        $this->assertSame(0, DB::table('chat_messages')->whereIn('id', $ids)->where('processingrequired', 1)->count(),
            'every held message is released by one run, not a few hundred at a time');
        $this->assertSame($count, DB::table('lockdown_holds')->whereIn('refid', $ids)->where('outcome', 'released')->count());

        $firstReleased = DB::table('lockdown_holds')->where('refid', $ids[0])->value('releasedat');
        $lastReleased = DB::table('lockdown_holds')->where('refid', end($ids))->value('releasedat');
        $this->assertLessThanOrEqual($lastReleased, $firstReleased, 'oldest first');
    }

    public function test_release_goes_through_the_ordinary_checks_so_a_marked_spammer_is_dropped(): void
    {
        $this->lockdown->press(null, 'wave');

        $poster = $this->createTestUser();
        $spammer = $this->createTestUser();
        $genuine = $this->createTestUser();
        $spamRoom = $this->createTestChatRoom($poster, $spammer);
        $okRoom = $this->createTestChatRoom($poster, $genuine);
        $spamMsg = $this->createTestChatMessage($spamRoom, $spammer, [
            'message' => 'Claim your free voucher', 'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);
        $okMsg = $this->createTestChatMessage($okRoom, $genuine, [
            'message' => 'Is the sofa still available', 'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);
        $this->hold($spamMsg->id, $spammer->id);
        $this->hold($okMsg->id, $genuine->id);

        // Support marks the sender in Support tools while the lockdown is on.
        DB::table('spam_users')->insert(['userid' => $spammer->id, 'collection' => 'Spammer', 'reason' => 'voucher wave']);

        $this->lockdown->setSurfaces(['chat' => false], null);
        $this->service->processIncoming();

        $spamRow = DB::table('chat_messages')->where('id', $spamMsg->id)->first();
        $this->assertEquals(0, $spamRow->processingrequired);
        $this->assertEquals(0, $spamRow->processingsuccessful, "the spammer's message is not delivered");
        $this->assertSame(ChatMessage::PROCESSFAIL_SPAMMER, $spamRow->processingfailreason);

        $okRow = DB::table('chat_messages')->where('id', $okMsg->id)->first();
        $this->assertEquals(1, $okRow->processingsuccessful, 'the genuine message is delivered');
        $this->assertSame('released', $this->holdFor($okMsg->id)->outcome);
    }

    public function test_held_nudge_is_released_like_any_other_message(): void
    {
        $this->lockdown->press(null, 'wave');

        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => '',
            'type' => ChatMessage::TYPE_NUDGE,
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);
        $this->hold($msg->id, $replier->id);

        $this->service->processIncoming();
        $this->assertEquals(1, DB::table('chat_messages')->where('id', $msg->id)->value('processingrequired'), 'held while chat is held');

        $this->lockdown->setSurfaces(['chat' => false], null);
        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
        $this->assertSame('released', $this->holdFor($msg->id)->outcome);
    }

    public function test_second_message_stops_processing_once_pressed_mid_run(): void
    {
        $poster = $this->createTestUser();
        $replier1 = $this->createTestUser();
        $replier2 = $this->createTestUser();
        $room1 = $this->createTestChatRoom($poster, $replier1);
        $room2 = $this->createTestChatRoom($poster, $replier2);

        $msg1 = $this->createTestChatMessage($room1, $replier1, ['processingrequired' => 1, 'processingsuccessful' => 0]);
        $msg2 = $this->createTestChatMessage($room2, $replier2, ['processingrequired' => 1, 'processingsuccessful' => 0]);

        // Presses as a side effect of the first message's per-item check, standing in for
        // another process pressing while this run is under way. Call 1 is the batch fetch,
        // call 2 the first message's check.
        $lockdown = new class extends LockdownService {
            public int $calls = 0;

            public function held(string $surface): bool
            {
                $this->calls++;
                $result = parent::held($surface);
                if ($this->calls === 2) {
                    $this->press(null, 'pressed mid run');
                }

                return $result;
            }
        };
        $service = new ChatProcessService(null, $lockdown);

        $service->processIncoming();

        $updated1 = DB::table('chat_messages')->where('id', $msg1->id)->first();
        $this->assertEquals(0, $updated1->processingrequired, 'already under way when the press landed, so it still completes');

        $updated2 = DB::table('chat_messages')->where('id', $msg2->id)->first();
        $this->assertEquals(1, $updated2->processingrequired, 'held at its next item, not waiting for the next run');
    }

    public function test_ack_is_written_for_each_message_passing_through(): void
    {
        $this->lockdown->press(null, 'wave');

        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);
        $this->createTestChatMessage($room, $replier, ['processingrequired' => 1, 'processingsuccessful' => 0]);

        $this->service->processIncoming();

        $ack = DB::table('lockdown_acks')->where('loop', 'chat-process')->first();
        $this->assertNotNull($ack, 'chat-process must ack so the presser sees this loop take effect');
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }
}
