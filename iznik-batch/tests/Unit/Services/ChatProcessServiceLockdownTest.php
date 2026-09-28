<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\ChatProcessService;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownTriageService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ChatProcessService::processIncoming under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, sections 10.6, 10.9, 11.4). The ordinary (no lockdown)
 * behaviour is covered by ChatProcessServiceTest; this file is only the lockdown branch.
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
        $this->service = new ChatProcessService(null, $this->lockdown, new LockdownTriageService($this->lockdown));
    }

    private function holdFor(int $chatMessageId): ?object
    {
        return DB::table('lockdown_holds')
            ->where('kind', LockdownTriageService::KIND_CHAT)
            ->where('refid', $chatMessageId)
            ->first();
    }

    // --- Hard mode: member-to-member traffic stays queued, untouched ---

    public function test_hard_mode_leaves_user2user_message_queued(): void
    {
        $this->lockdown->press(null, 'wave'); // full preset: chat held, chat_mode hard

        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, ['processingrequired' => 1, 'processingsuccessful' => 0]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->processingrequired, 'hard lockdown must not touch member-to-member chat');
        $this->assertNull($this->holdFor($msg->id), 'the triage command creates holds, not this run, while nothing is released yet');
    }

    public function test_hard_mode_still_processes_user2mod_chat(): void
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
        $this->assertEquals(0, $updated->processingrequired, 'User2Mod must flow even under a hard lockdown');
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    // --- Soft mode: triage by class ---

    public function test_soft_mode_low_risk_delivered_normally_and_hold_released(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setSurfaces(['chat_mode' => LockdownService::CHAT_SOFT], null);

        $poster = $this->createTestUser();
        $replier = $this->createTestUser(['added' => now()->subDays(90)]);
        $room = $this->createTestChatRoom($poster, $replier);
        // Earlier activity establishes the sender for classify()'s "low" branch.
        $this->createTestChatMessage($room, $replier, [
            'date' => now()->subDays(10), 'processingrequired' => 0, 'processingsuccessful' => 1,
        ]);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => 'Is this still available',
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
        $this->assertEquals(0, $updated->reviewrequired, 'low risk is delivered, not held for review');

        $hold = $this->holdFor($msg->id);
        $this->assertNotNull($hold);
        $this->assertSame('low', $hold->risk);
        $this->assertSame('released', $hold->outcome);
        $this->assertNotNull($hold->releasedat);

        // A soft-mode User2User delivery is intended, not a leak (section 11.4/11.6):
        // the Go PATCH stats compute chat leaks from lockdown_holds directly, so this
        // service no longer keeps its own counter for the same thing.
        $leaked = DB::table('lockdown_counters')->where('kind', 'leaked:chat:user2user')->first();
        $this->assertNull($leaked, 'soft-mode delivery is intended; nothing here counts it as a leak any more');
    }

    /**
     * Nudge rows (chat/chatroom.go's handleNudge) are created with processingrequired = 1
     * while chat is held, exactly like an ordinary message, so they enter this same queue
     * (section 11.9). A Nudge carries no member-entered text, so isContentCheckable()
     * correctly excludes it from block-keyword and concern-keyword checking - there is
     * nothing to check - but it must still be classified on its SENDER by the same
     * triage->classify() call every other User2User message goes through, and released
     * the same way, not skipped or left queued. The empty text must not be mistaken for a
     * spam cluster of identical messages: clusterInfo() short-circuits to count 0 for an
     * empty folded string, so it never reaches the >= CLUSTER_MIN_SIZE spam check.
     */
    public function test_nudge_message_is_classified_on_sender_and_released_like_other_messages(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setSurfaces(['chat_mode' => LockdownService::CHAT_SOFT], null);

        $poster = $this->createTestUser();
        $replier = $this->createTestUser(['added' => now()->subDays(90)]);
        $room = $this->createTestChatRoom($poster, $replier);
        // Earlier activity establishes the sender for classify()'s "low" branch.
        $this->createTestChatMessage($room, $replier, [
            'date' => now()->subDays(10), 'processingrequired' => 0, 'processingsuccessful' => 1,
        ]);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => '',
            'type' => ChatMessage::TYPE_NUDGE,
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired, 'a held Nudge must still be released, not left queued forever');
        $this->assertEquals(1, $updated->processingsuccessful);
        $this->assertEquals(0, $updated->reviewrequired, 'an established sender with no link/phrase classifies low, same as ordinary traffic');

        $hold = $this->holdFor($msg->id);
        $this->assertNotNull($hold, 'a Nudge goes through the same triage hold as any other User2User message');
        $this->assertSame('low', $hold->risk, 'classify() is keyed on the sender - empty text must not read as a spam cluster');
        $this->assertSame('released', $hold->outcome);
    }

    public function test_soft_mode_risky_held_for_review_with_lockdown_reportreason(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setSurfaces(['chat_mode' => LockdownService::CHAT_SOFT], null);

        $poster = $this->createTestUser();
        // Fresh account, no earlier activity, no link: classify() falls through to risky.
        $replier = $this->createTestUser(['added' => now()->subMinutes(2)]);
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => 'Is this still available',
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);
        $this->assertSame('Lockdown', $updated->reportreason);
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);

        $hold = $this->holdFor($msg->id);
        $this->assertSame('risky', $hold->risk);
        $this->assertSame('review', $hold->outcome);
    }

    public function test_soft_mode_spam_risk_dropped_like_block_keyword(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setSurfaces(['chat_mode' => LockdownService::CHAT_SOFT], null);

        $poster = $this->createTestUser();
        // Unestablished account + a link: classify() calls this spam without needing
        // the sender in spam_users (that earlier, unrelated check is covered below).
        $replier = $this->createTestUser(['added' => now()->subMinutes(2)]);
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => 'Check this out https://example.com/voucher',
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired);
        $this->assertEquals(1, $updated->reviewrejected);
        $this->assertSame('Lockdown', $updated->reportreason);
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful, 'a lockdown drop is a decision, not a failure');

        $hold = $this->holdFor($msg->id);
        $this->assertSame('spam', $hold->risk);
        $this->assertSame('rejected', $hold->outcome);
    }

    /**
     * Support's release class (Go PATCH /lockdown releaseclass, decision release) sets
     * lockdown_holds.outcome = 'approved' on a chat hold that was 'review'. Chat's own
     * soft-mode classification only ever writes 'review' for a risky message (see
     * test_soft_mode_risky_held_for_review_with_lockdown_reportreason above) - nothing
     * in the normal flow ever acts on 'approved', so without this the message stayed
     * reviewrequired = 1 forever. Approve it the same way a moderator's chat approve
     * does: reviewrequired 0, reviewedby the system modtools user, and the same
     * roster/latestmessage/notification effects an ordinary delivery gets.
     */
    public function test_release_class_approved_review_message_is_approved_and_notified(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setSurfaces(['chat_mode' => LockdownService::CHAT_SOFT], null);

        $sysUser = $this->createTestUser(['email_preferred' => 'modtools@modtools.org']);

        $poster = $this->createTestUser();
        $replier = $this->createTestUser(['added' => now()->subMinutes(2)]);
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => 'Is this still available',
            'reviewrequired' => 1,
            'reportreason' => 'Lockdown',
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownTriageService::KIND_CHAT,
            'refid' => $msg->id,
            'userid' => $replier->id,
            'risk' => 'risky',
            'outcome' => 'approved',
            'created' => now(),
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'release-class approval must actually deliver the message, not leave it in review forever');
        $this->assertEquals($sysUser->id, $updated->reviewedby);

        $updatedRoom = DB::table('chat_rooms')->where('id', $room->id)->first();
        $this->assertNotNull($updatedRoom->latestmessage, 'delivered like any other message, so the room resurfaces in the recipient\'s list');

        $task = DB::table('background_tasks')->where('task_type', 'push_notify_chat_message')->first();
        $this->assertNotNull($task, 'the recipient is notified now the message is actually delivered');

        $hold = $this->holdFor($msg->id);
        $this->assertSame('released', $hold->outcome);
        $this->assertNotNull($hold->releasedat);
    }

    /**
     * A hold whose message a moderator has already actioned by hand (no longer
     * reviewrequired = 1 with reportreason 'Lockdown') has nothing left for this to do -
     * the release class only closes out the hold so it stops being picked up.
     */
    public function test_release_class_approved_hold_already_actioned_by_moderator_is_just_closed(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setSurfaces(['chat_mode' => LockdownService::CHAT_SOFT], null);

        $poster = $this->createTestUser();
        $replier = $this->createTestUser(['added' => now()->subMinutes(2)]);
        $room = $this->createTestChatRoom($poster, $replier);
        $mod = $this->createTestUser();
        // A moderator already approved this one by hand before Support's release class
        // caught up with it.
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => 'Is this still available',
            'reviewrequired' => 0,
            'reviewedby' => $mod->id,
            'reportreason' => 'Lockdown',
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownTriageService::KIND_CHAT,
            'refid' => $msg->id,
            'userid' => $replier->id,
            'risk' => 'risky',
            'outcome' => 'approved',
            'created' => now(),
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals($mod->id, $updated->reviewedby, 'the moderator\'s own review must not be overwritten');

        $hold = $this->holdFor($msg->id);
        $this->assertSame('released', $hold->outcome);
    }

    // --- Lifting: backlog release ---

    public function test_lifting_releases_backlog_hold_and_processes_the_message(): void
    {
        $this->lockdown->press(null, 'wave'); // hard, so the message below never reached this service

        $poster = $this->createTestUser();
        $replier = $this->createTestUser(['added' => now()->subDays(90)]);
        $room = $this->createTestChatRoom($poster, $replier);
        $this->createTestChatMessage($room, $replier, [
            'date' => now()->subDays(10), 'processingrequired' => 0, 'processingsuccessful' => 1,
        ]);
        $msg = $this->createTestChatMessage($room, $replier, [
            'message' => 'Is this still available',
            'processingrequired' => 1, 'processingsuccessful' => 0,
        ]);
        // Simulates what lockdown:triage's createHolds() would already have done while
        // chat was hard-held (this service never touches a hard-held message, so no hold
        // is created via processIncoming while hard mode is on - see the test above).
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownTriageService::KIND_CHAT,
            'refid' => $msg->id,
            'userid' => $replier->id,
            'created' => now(),
        ]);

        $this->lockdown->setSurfaces(['chat' => false], null);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
        $this->assertEquals(0, $updated->reviewrequired);

        $hold = $this->holdFor($msg->id);
        $this->assertSame('released', $hold->outcome);
        $this->assertNotNull($hold->releasedat);
    }

    // --- Support marking a sender spam mid-incident ---

    public function test_spam_marked_hold_dropped_on_next_run_whatever_the_held_state(): void
    {
        $this->lockdown->press(null, 'wave'); // still hard-held; this must not wait for a lift

        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);
        $msg = $this->createTestChatMessage($room, $replier, [
            'processingrequired' => 0, 'processingsuccessful' => 1,
        ]);
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownTriageService::KIND_CHAT,
            'refid' => $msg->id,
            'userid' => $replier->id,
            'risk' => 'spam',
            'outcome' => 'spam_marked',
            'created' => now(),
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrejected);
        $this->assertSame('Lockdown', $updated->reportreason);

        $hold = $this->holdFor($msg->id);
        $this->assertSame('rejected', $hold->outcome, 'spam_marked is a to-do for the batch, not a final state');
    }

    // --- Mid-run press (section 11.6 point 2) ---

    public function test_second_message_stops_processing_once_hard_pressed_mid_run(): void
    {
        $poster = $this->createTestUser();
        $replier1 = $this->createTestUser();
        $replier2 = $this->createTestUser();
        $room1 = $this->createTestChatRoom($poster, $replier1);
        $room2 = $this->createTestChatRoom($poster, $replier2);

        $msg1 = $this->createTestChatMessage($room1, $replier1, ['processingrequired' => 1, 'processingsuccessful' => 0]);
        $msg2 = $this->createTestChatMessage($room2, $replier2, ['processingrequired' => 1, 'processingsuccessful' => 0]);

        // Presses (full preset: chat held, chat_mode hard) as a side effect of the first
        // message's held() check succeeding, simulating another process pressing the
        // switch while this run is already under way. Nothing was held when the batch was
        // fetched, so both messages are in $messages already; call #1 below is the
        // pre-loop check, call #2 is the first message's per-item check.
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
        $service = new ChatProcessService(null, $lockdown, new LockdownTriageService($lockdown));

        $service->processIncoming();

        $updated1 = DB::table('chat_messages')->where('id', $msg1->id)->first();
        $this->assertEquals(0, $updated1->processingrequired, 'already under way when the press landed, so it still completes');

        $updated2 = DB::table('chat_messages')->where('id', $msg2->id)->first();
        $this->assertEquals(1, $updated2->processingrequired, 'hard-held at its next item, not waiting for the next run');
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
