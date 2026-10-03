<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ChatProcessService;
use App\Services\Judgement\FakeJudge;
use App\Services\Judgement\Verdict;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatProcessServiceTest extends TestCase
{
    protected ChatProcessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatProcessService;
    }

    // --- Ban handling (Discourse: replies silently destroyed) ---

    /**
     * A ban is a single site-wide fact about the sender (users.banned) - there is one
     * national site, so there is no community-scoped standing left to check. A reply
     * from someone who is not banned must be delivered.
     */
    public function test_reply_delivered_when_sender_is_not_banned(): void
    {
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);

        $message = $this->createTestMessage($poster);

        $msg = $this->createTestChatMessage($room, $replier, [
            'processingrequired' => 1, 'processingsuccessful' => 0,
            'platform' => 1, 'refmsgid' => $message->id,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->processingsuccessful,
            'a reply from someone in good standing must be delivered');
    }

    /**
     * The protection that must survive: someone banned site-wide (users.banned) is
     * blocked from reaching another member.
     */
    public function test_reply_suppressed_when_sender_is_banned(): void
    {
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);

        $message = $this->createTestMessage($poster);

        DB::table('users')->where('id', $replier->id)->update([
            'banned' => now(), 'bannedby' => $poster->id,
        ]);

        $msg = $this->createTestChatMessage($room, $replier, [
            'processingrequired' => 1, 'processingsuccessful' => 0,
            'platform' => 1, 'refmsgid' => $message->id,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingsuccessful,
            'a banned member stays blocked from messaging other members');
        $this->assertEquals(ChatMessage::PROCESSFAIL_BANNED_IN_COMMON, $updated->processingfailreason,
            'support tools must be able to see WHY the reply never arrived');
    }

    /**
     * The silence was the worst part: a suppressed reply looked identical to one that was
     * never written, so it got misdiagnosed. Record why on the message itself.
     */
    public function test_spam_suppression_records_a_reason_for_support(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id, 'collection' => 'Spammer', 'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(ChatMessage::PROCESSFAIL_SPAMMER, $updated->processingfailreason);
    }

    /**
     * Someone on the spammer list cannot reach the volunteers either. Their mail to the
     * volunteers address is already dropped on the way in, but the Contact button opens
     * a chat with the volunteers and nothing stopped that: the spam check only covered
     * member-to-member chats. A ban is different and deliberately still gets through -
     * that is how a banned member appeals (Discourse 10149).
     */
    public function test_a_spammer_cannot_message_the_volunteers(): void
    {
        $spammer = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $spammer, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user2' => null,
        ]);
        DB::table('spam_users')->insert([
            'userid' => $spammer->id, 'collection' => 'Spammer', 'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $spammer, [
            'processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingsuccessful,
            'a spammer message to the volunteers must not be delivered');
        $this->assertEquals(ChatMessage::PROCESSFAIL_SPAMMER, $updated->processingfailreason);
    }

    /**
     * A ban is not the spammer list. Someone banned site-wide must still be able to
     * write to the volunteers, because that is the route for appealing the ban
     * (Edward's decision on Discourse 10149).
     */
    public function test_a_banned_member_can_still_message_the_volunteers(): void
    {
        $member = $this->createTestUser();
        $room = $this->createTestChatRoom($member, $member, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user2' => null,
        ]);
        DB::table('users')->where('id', $member->id)->update([
            'banned' => now(), 'bannedby' => $member->id,
        ]);

        $msg = $this->createTestChatMessage($room, $member, [
            'processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->processingsuccessful,
            'a banned member must still be able to appeal to the volunteers');
        $this->assertNull($updated->processingfailreason);
    }

    /**
     * Someone only PROPOSED for the spammer list is not on it yet, and writing to the
     * volunteers is how they would argue they should not be added. Member-to-member is
     * unchanged: a pending addition is still held back there, as it always was.
     */
    public function test_a_pending_spammer_can_still_message_the_volunteers(): void
    {
        $proposed = $this->createTestUser();
        $room = $this->createTestChatRoom($proposed, $proposed, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user2' => null,
        ]);
        DB::table('spam_users')->insert([
            'userid' => $proposed->id, 'collection' => 'PendingAdd', 'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $proposed, [
            'processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->processingsuccessful,
            'a proposed spammer must still be able to put their case to the volunteers');
    }

    // --- Basic processing ---

    public function test_message_with_processingrequired_gets_marked_processed(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $count = $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
        $this->assertEquals(0, $updated->reviewrequired);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_already_processed_message_is_not_touched(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $count = $this->service->processIncoming();

        $this->assertEquals(0, $count);
    }

    public function test_returns_count_of_processed_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, ['processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1]);
        $this->createTestChatMessage($room, $user2, ['processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1]);

        $count = $this->service->processIncoming();

        $this->assertEquals(2, $count);
    }

    // --- Spammer checks ---

    public function test_message_from_confirmed_spammer_fails_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(0, $updated->processingsuccessful);
    }

    public function test_message_from_pending_add_spammer_fails_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id,
            'collection' => 'PendingAdd',
            'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(0, $updated->processingsuccessful);
    }

    public function test_message_from_whitelisted_user_processes_normally(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id,
            'collection' => 'Whitelisted',
            'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    // --- Roster update ---

    public function test_email_message_updates_sender_roster(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Create roster entry for user1 in this room
        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_OFFLINE,
            'lastmsgseen' => null,
            'lastmsgemailed' => null,
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 0,  // email reply
        ]);

        $this->service->processIncoming();

        $roster = DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $user1->id)->first();
        $this->assertEquals($msg->id, $roster->lastmsgseen);
        $this->assertEquals($msg->id, $roster->lastmsgemailed);
    }

    // --- Closed chat reopen ---

    public function test_closed_chat_is_reopened_after_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // user2's roster entry is CLOSED
        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_CLOSED,
            'lastmsgseen' => null,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $roster = DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $user2->id)->first();
        $this->assertEquals(ChatRoster::STATUS_OFFLINE, $roster->status);
    }

    public function test_blocked_chat_stays_blocked_after_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // user2's roster entry is BLOCKED
        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_BLOCKED,
            'lastmsgseen' => null,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $roster = DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $user2->id)->first();
        $this->assertEquals(ChatRoster::STATUS_BLOCKED, $roster->status);
    }

    // --- Review cascade ---

    public function test_message_held_when_previous_message_under_review(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Previous message from user2 is under review
        $this->createTestChatMessage($room, $user2, [
            'reviewrequired' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // New message from user1 needs processing
        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    // Regression: Discourse #9656. Once a member has a message held for review,
    // EVERY subsequent message must also be held until a mod clears them. V1's
    // chat_process daemon processed one message at a time, so the hold chain
    // propagated naturally. This service processes a whole burst at once, and the
    // chain query previously looked at the newest OTHER row (id != $id) — which,
    // mid-batch, is a later not-yet-processed message with reviewrequired = 0, so
    // the chain broke and innocuous messages between worry-word ones were
    // delivered. The chain must follow the immediately preceding message.
    public function test_hold_chain_propagates_across_a_burst_of_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // An earlier message from user1 is already held for review.
        $this->createTestChatMessage($room, $user1, [
            'reviewrequired' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Two further innocuous messages arrive in the SAME batch (both pending).
        // The second has the higher id, so under the old id != $id query it is the
        // "newest other row" seen while processing the first.
        $first = $this->createTestChatMessage($room, $user1, [
            'message' => 'innocuous one',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);
        $second = $this->createTestChatMessage($room, $user1, [
            'message' => 'innocuous two',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $u1 = DB::table('chat_messages')->where('id', $first->id)->first();
        $u2 = DB::table('chat_messages')->where('id', $second->id)->first();
        $this->assertEquals(1, $u1->reviewrequired, 'message after a held one must also be held');
        $this->assertEquals(1, $u2->reviewrequired, 'hold chain must continue through the whole burst');
    }

    // --- Content checks for Moderated members (regression: Discourse #9706) ---
    //
    // V1 ChatMessage::process() ran Spam::checkReview() on Moderated members'
    // messages and held any that matched. That scan was dropped when chat
    // processing was migrated to ChatProcessService, letting graphic/spam chat
    // content through unflagged. These tests pin the restored behaviour.

    public function test_held_message_reportreason_reflects_the_specific_check(): void
    {
        // A money symbol must be surfaced as reportreason 'Money', not the generic
        // 'Spam', so the review UI shows "It looks like it refers to money."
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'I can do it for £50 if you collect',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'A money symbol from a Moderated member should be held');
        $this->assertEquals('Money', $updated->reportreason, 'reportreason must name the specific check (Money), not generic Spam');
    }

    public function test_moderated_user_clean_message_is_not_held(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hi, is the lamp still available please?',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'Clean message from a Moderated member should pass through');
        $this->assertNull($updated->reportreason);
    }

    public function test_moderated_user_message_with_phone_number_is_not_held(): void
    {
        // Sharing a phone number to arrange a handover is normal, so chat
        // messages are deliberately NOT phone-number checked (V1 parity).
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Text me on 07700 900123 to arrange',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'A bare phone number in chat should NOT be held for review');
        $this->assertNull($updated->reportreason);
    }

    // --- Judge fallback for Moderated members (ai-judgement.md) ---
    //
    // The deterministic checks above catch keyword-listable abuse. They cannot
    // catch a paraphrased money ask or a slur-free insult, so a message that
    // passes them clean is also asked of the judge - but only for Moderated
    // members, only when the deterministic check found nothing, and a judge
    // that is unavailable must never be read as a hold.

    public function test_moderated_user_message_judge_free_is_held_with_money_reportreason(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'give me a little something for it, cash is fine',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $judge = (new FakeJudge())->when('cash is fine', [
            'free' => ['answer' => 'yes', 'confidence' => 0.95, 'reason' => 'Asks for payment.'],
        ]);
        $service = new ChatProcessService(judge: $judge);
        $service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'A confident judge "free" answer should hold the message');
        $this->assertEquals('Money', $updated->reportreason, 'free maps to Money (config freegle.judgement.chat_reportreason)');
    }

    public function test_moderated_user_message_judge_scam_is_held_with_link_reportreason(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'message me on this other app to sort payment',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $judge = (new FakeJudge())->when('this other app', [
            'scam' => ['answer' => 'yes', 'confidence' => 0.9, 'reason' => 'Tries to move off-platform.'],
        ]);
        $service = new ChatProcessService(judge: $judge);
        $service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'A confident judge "scam" answer should hold the message');
        $this->assertEquals('Link', $updated->reportreason, 'scam maps to Link (config freegle.judgement.chat_reportreason)');
    }

    public function test_moderated_user_message_judge_decent_is_held_with_abuse_reportreason(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'you are a pathetic waste of space, do not come near me again',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $judge = (new FakeJudge())->when('pathetic waste of space', [
            'decent' => ['answer' => 'yes', 'confidence' => 0.97, 'reason' => 'Abusive towards the recipient.'],
        ]);
        $service = new ChatProcessService(judge: $judge);
        $service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'A confident judge "decent" answer should hold the message');
        $this->assertEquals('Abuse', $updated->reportreason, 'decent maps to Abuse (config freegle.judgement.chat_reportreason)');
    }

    public function test_moderated_user_message_judge_low_confidence_is_not_held(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'maybe something for it, not sure',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        // Below config('freegle.judgement.threshold') default of 0.8 - must not hold.
        $judge = (new FakeJudge())->when('not sure', [
            'free' => ['answer' => 'yes', 'confidence' => 0.5, 'reason' => 'Ambiguous.'],
        ]);
        $service = new ChatProcessService(judge: $judge);
        $service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'A low-confidence judge answer must not hold the message');
    }

    public function test_moderated_user_message_unavailable_judge_is_not_held(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'is this still available, quirkyphrase',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        // Unavailable is "no signal", never a positive hold signal - the API being
        // down must not turn into extra moderation load or false holds.
        $judge = (new FakeJudge())->unavailableWhen('quirkyphrase');
        $service = new ChatProcessService(judge: $judge);
        $service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'An unavailable judge must never cause a hold');
        $this->assertNull($updated->reportreason);
    }

    public function test_moderated_user_message_deterministic_check_wins_over_judge(): void
    {
        // A message that trips BOTH a deterministic check (a money symbol) and
        // would (if asked) get a judge "yes" must be held with the deterministic
        // check's specific reason - the judge is only a fallback for what the
        // cheap checks miss, not a second opinion that could override or relabel
        // it.
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'I can do it for £50, cash in hand',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        // If this judge were ever consulted it would flag 'decent', which would
        // wrongly relabel the reportreason as Abuse instead of Money.
        $judge = (new FakeJudge())->when('cash in hand', [
            'decent' => ['answer' => 'yes', 'confidence' => 0.99, 'reason' => 'Should not be reached.'],
        ]);
        $service = new ChatProcessService(judge: $judge);
        $service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);
        $this->assertEquals('Money', $updated->reportreason, 'the deterministic check must win, not the judge');
    }

    public function test_unmoderated_user_message_is_not_content_checked(): void
    {
        // Unmoderated members bypass content checks entirely (V1 parity) - not
        // just the judge fallback, but every deterministic check too.
        $sender = $this->createTestUser(['chatmodstatus' => 'Unmoderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'I can do it for £50 if you collect',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'Unmoderated members bypass content checks (V1 parity)');
    }

    public function test_fully_moderated_user_message_is_always_held(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Fully']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hi, is the lamp still available please?',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'Fully moderated members have every message held');
        $this->assertEquals('Spam', $updated->reportreason);
    }

    public function test_moderated_user_system_message_is_not_content_checked(): void
    {
        // System/templated messages must never be content-checked (or held), no
        // matter what a deterministic check would otherwise catch in their text.
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'System note: I can do it for £50 if you collect',
            'type' => ChatMessage::TYPE_SYSTEM,
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'System/templated messages are not content checked');
    }

    // --- Push notification enqueue (Discourse: chat push lost since 2026-05-08) ---
    //
    // V1 ChatMessage::process() called notifyMembers() after a message passed
    // spam/review/ban checks. That FCM-push side was dropped when chat_process.php
    // was migrated to ChatProcessService (commit 5cbb607b7), leaving users with
    // no push for new chat messages — only the delayed digest email. Restore
    // V1 parity by enqueuing a push_notify_chat_message background task for
    // every successfully processed, non-held message.

    public function test_successfully_processed_message_enqueues_push_notification_task(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $task = DB::table('background_tasks')
            ->where('task_type', 'push_notify_chat_message')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($task, 'Expected a push_notify_chat_message task to be queued for a successfully processed message');
        $data = json_decode($task->data, true);
        $this->assertEquals($msg->id, $data['message_id']);
    }

    public function test_held_for_review_message_does_not_enqueue_push(): void
    {
        // Fully-moderated sender → every message is held automatically.
        $sender = $this->createTestUser(['chatmodstatus' => 'Fully']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $task = DB::table('background_tasks')
            ->where('task_type', 'push_notify_chat_message')
            ->first();

        $this->assertNull($task, 'Held-for-review messages must not push — V1 invariant');

        // Sanity: message was processed and held, not discarded.
        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    public function test_spammer_message_does_not_enqueue_push(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        DB::table('spam_users')->insert([
            'userid' => $sender->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $this->createTestChatMessage($room, $sender, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $task = DB::table('background_tasks')
            ->where('task_type', 'push_notify_chat_message')
            ->first();

        $this->assertNull($task, 'Spammer messages must not push');
    }

    /**
     * Experiment: warn, do not hold. A message the content check flags is still marked
     * reviewrequired for moderators, but with the flag on it is delivered: the recipient
     * is pushed and the room surfaces in their list.
     */
    public function test_warn_not_hold_delivers_a_held_message(): void
    {
        config(['freegle.moderation.chat_warn_not_hold' => true]);

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2, ['latestmessage' => now()->subDays(40)]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'message' => 'Pay me first at https://not-a-whitelisted-site.example/deal',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'moderators still see it in review');
        $this->assertNotNull($updated->reportreason);

        $task = DB::table('background_tasks')
            ->where('task_type', \App\Models\BackgroundTask::TASK_PUSH_NOTIFY_CHAT_MESSAGE)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($task, 'the recipient is pushed about a held message');
        $this->assertEquals($msg->id, json_decode($task->data, true)['message_id']);

        $latest = DB::table('chat_rooms')->where('id', $room->id)->value('latestmessage');
        $this->assertGreaterThan(now()->subDay(), \Carbon\Carbon::parse($latest), 'the room surfaces in the list');
    }

    public function test_hold_without_the_flag_neither_pushes_nor_surfaces(): void
    {
        config(['freegle.moderation.chat_warn_not_hold' => false]);

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $old = now()->subDays(40);
        $room = $this->createTestChatRoom($user1, $user2, ['latestmessage' => $old]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'message' => 'Pay me first at https://not-a-whitelisted-site.example/deal',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);

        $pushed = DB::table('background_tasks')
            ->where('task_type', \App\Models\BackgroundTask::TASK_PUSH_NOTIFY_CHAT_MESSAGE)
            ->get()
            ->filter(fn ($t) => (int) (json_decode($t->data, true)['message_id'] ?? 0) === (int) $msg->id);
        $this->assertCount(0, $pushed, 'a held message is not pushed today');

        $latest = DB::table('chat_rooms')->where('id', $room->id)->value('latestmessage');
        $this->assertEquals($old->format('Y-m-d H:i:s'), \Carbon\Carbon::parse($latest)->format('Y-m-d H:i:s'));
    }

    /**
     * A shadow ban is a decision about the sender, not the message: it stays a hold under
     * the experiment, with no push and no room surfacing.
     */
    public function test_warn_not_hold_keeps_a_shadow_banned_sender_held(): void
    {
        config(['freegle.moderation.chat_warn_not_hold' => true]);

        $sender = $this->createTestUser(['chatmodstatus' => 'Fully']);
        $recipient = $this->createTestUser();
        $old = now()->subDays(40);
        $room = $this->createTestChatRoom($sender, $recipient, ['latestmessage' => $old]);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hello there',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);

        $pushed = DB::table('background_tasks')
            ->where('task_type', \App\Models\BackgroundTask::TASK_PUSH_NOTIFY_CHAT_MESSAGE)
            ->get()
            ->filter(fn ($t) => (int) (json_decode($t->data, true)['message_id'] ?? 0) === (int) $msg->id);
        $this->assertCount(0, $pushed, 'a shadow-banned sender is not delivered');

        $latest = DB::table('chat_rooms')->where('id', $room->id)->value('latestmessage');
        $this->assertEquals($old->format('Y-m-d H:i:s'), \Carbon\Carbon::parse($latest)->format('Y-m-d H:i:s'));
    }
}
