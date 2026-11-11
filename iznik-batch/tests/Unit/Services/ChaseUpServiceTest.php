<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageOutcome;
use App\Services\ChaseUpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ChaseUpServiceTest extends TestCase
{
    protected ChaseUpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure feature flag is enabled for tests.
        config(['freegle.mail.enabled_types' => config('freegle.mail.enabled_types') . ',ChaseUp']);
        $this->service = new ChaseUpService();
    }

    /**
     * Create a chase-up eligible message: our domain, approved, max reposts reached,
     * has a chat reply old enough, no outcome.
     */
    private function createChaseCandidate(
        ?object $user = null,
        int $hoursOld = 500,
        int $autoreposts = 5,
        int $replyHoursAgo = 200,
    ): array {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $user ?? $this->createTestUser();

        DB::table('users')->where('id', $user->id)->update(['emailfrequency' => -1]);

        $message = $this->createTestMessage($user, [
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        // Set arrival old enough and autoreposts at max.
        DB::table('messages')
            ->where('id', $message->id)
            ->update([
                'arrival' => now()->subHours($hoursOld),
                'autoreposts' => $autoreposts,
            ]);

        // Create a chat reply about this message.
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $replier);
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $message->id,
            'date' => now()->subHours($replyHoursAgo),
        ]);

        return [
            'user' => $user,
            'message' => $message,
            'replier' => $replier,
            'room' => $room,
        ];
    }

    public function test_an_intended_taken_on_a_split_post_marks_its_items_gone(): void
    {
        // TAKEN from the chase-up link is recorded here, not by the Go API, and a split post
        // is counted item by item; without its items marked gone it would count nothing.
        $message = $this->createTestMessage($this->createTestUser());
        DB::table('messages_bulk_items')->insert([
            ['msgid' => $message->id, 'name' => 'Sofa', 'quantity' => 1, 'available' => 1],
            ['msgid' => $message->id, 'name' => 'Chairs', 'quantity' => 2, 'available' => 1],
        ]);
        DB::table('messages_autosplit')->insert(['msgid' => $message->id, 'state' => 'Split']);
        DB::table('messages')->where('id', $message->id)->update(['availableinitially' => 3, 'availablenow' => 3]);
        DB::table('messages_outcomes_intended')->insert([
            'msgid' => $message->id,
            'outcome' => 'Taken',
            'timestamp' => now()->subHour(),
        ]);

        $this->assertSame(1, app(ChaseUpService::class)->processIntendedOutcomes());

        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $message->id)->where('available', 1)->count());
        $this->assertSame(0, (int) DB::table('messages')->where('id', $message->id)->value('availablenow'));
    }

    public function test_an_intended_taken_on_a_clearance_leaves_its_items(): void
    {
        $message = $this->createTestMessage($this->createTestUser());
        DB::table('messages_bulk_items')->insert(['msgid' => $message->id, 'name' => 'Desk', 'quantity' => 4, 'available' => 1]);
        DB::table('messages_outcomes_intended')->insert([
            'msgid' => $message->id,
            'outcome' => 'Taken',
            'timestamp' => now()->subHour(),
        ]);

        app(ChaseUpService::class)->processIntendedOutcomes();

        $this->assertSame(1, DB::table('messages_bulk_items')->where('msgid', $message->id)->where('available', 1)->count());
    }

    public function test_no_messages_returns_zero_stats(): void
    {
        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
        $this->assertEquals(0, $stats['skipped']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_chases_up_eligible_message(): void
    {
        $data = $this->createChaseCandidate();

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['chased']);

        // Verify lastchaseup was set.
        $mg = DB::table('messages')
            ->where('id', $data['message']->id)
            ->first();
        $this->assertNotNull($mg->lastchaseup);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $data = $this->createChaseCandidate();

        $stats = $this->service->process(dryRun: true);

        $this->assertEquals(1, $stats['chased']);

        // lastchaseup should still be null.
        $mg = DB::table('messages')
            ->where('id', $data['message']->id)
            ->first();
        $this->assertNull($mg->lastchaseup);
    }

    public function test_skips_message_without_chat_reply(): void
    {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['emailfrequency' => -1]);

        $message = $this->createTestMessage($user, [
            'fromaddr' => 'test@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages')
            ->where('id', $message->id)
            ->update([
                'arrival' => now()->subHours(500),
                'autoreposts' => 5,
            ]);

        // No chat reply — message won't even appear in candidates (INNER JOIN).
        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
    }

    public function test_skips_non_our_domain(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['emailfrequency' => -1]);

        $message = $this->createTestMessage($user, [
            'fromaddr' => 'test@external.com',
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages')
            ->where('id', $message->id)
            ->update([
                'arrival' => now()->subHours(500),
                'autoreposts' => 5,
            ]);

        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $replier);
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $message->id,
            'date' => now()->subHours(200),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
        $this->assertGreaterThan(0, $stats['skipped']);
    }

    public function test_skips_message_not_at_max_reposts(): void
    {
        // Only 2 autoreposts, max is 5 — canChaseup should return false.
        $data = $this->createChaseCandidate(autoreposts: 2);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
    }

    public function test_a_post_just_past_the_chaseup_interval_is_still_fetched(): void
    {
        // The database is now asked to leave out posts whose last chase-up is too recent,
        // instead of fetching every one and discarding it. The danger in doing that is
        // asking for too little: a post left out of the fetch is never considered again,
        // so its chase-up is lost silently. The window therefore reaches back a day
        // further than the rule it stands in for.
        //
        // Defaults give an offer an interval of five days, so a chase-up sent five days
        // and an hour ago is due. It must survive the fetch.
        $data = $this->createChaseCandidate();
        DB::table('messages')
            ->where('id', $data['message']->id)
            ->update(['lastchaseup' => now()->subHours(5 * 24 + 1)]);

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['chased'], 'a due chase-up must not be filtered out of the fetch');
    }

    public function test_a_recently_chased_post_is_not_chased_again(): void
    {
        // The other side of the same window. This one is fetched - the window is
        // deliberately a day wider than the rule - and then declined in PHP, which is
        // the safe way round for the two to disagree.
        $data = $this->createChaseCandidate();
        DB::table('messages')
            ->where('id', $data['message']->id)
            ->update(['lastchaseup' => now()->subHours(4 * 24)]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased'], 'a post chased up four days ago is not due again yet');
    }

    public function test_skips_message_with_outcome(): void
    {
        $data = $this->createChaseCandidate();

        MessageOutcome::create([
            'msgid' => $data['message']->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
    }

    public function test_skips_message_with_recent_reply(): void
    {
        // Reply only 1 hour ago — chaseup interval not met.
        $data = $this->createChaseCandidate(replyHoursAgo: 1);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
        $this->assertGreaterThan(0, $stats['skipped']);
    }

    public function test_skips_deleted_message(): void
    {
        $data = $this->createChaseCandidate();

        DB::table('messages')->where('id', $data['message']->id)->update([
            'deleted' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
    }

    public function test_skips_post_from_deleted_user(): void
    {
        $data = $this->createChaseCandidate();

        DB::table('users')->where('id', $data['user']->id)->update(['deleted' => now()]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
        $this->assertNull(DB::table('messages')->where('id', $data['message']->id)->value('lastchaseup'));
    }

    public function test_a_modmail_is_not_a_reply(): void
    {
        $data = $this->createChaseCandidate();

        DB::table('chat_messages')
            ->where('chatid', $data['room']->id)
            ->update(['type' => 'ModMail']);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased'], 'a post whose only chat row is a ModMail has no reply to chase');
    }

    public function test_a_recent_modmail_does_not_hide_an_old_reply(): void
    {
        $data = $this->createChaseCandidate();

        $this->createTestChatMessage($data['room'], $data['replier'], [
            'type' => 'ModMail',
            'date' => now()->subHour(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['chased']);
    }

    private function holdReply(array $data, string $status): void
    {
        $chatMessage = DB::table('chat_messages')->where('chatid', $data['room']->id)->first();

        DB::table('rippling_held_replies')->insert([
            'chatid' => $chatMessage->chatid,
            'chatmsgid' => $chatMessage->id,
            'msgid' => $data['message']->id,
            'replieruserid' => $data['replier']->id,
            'status' => $status,
        ]);
    }

    public function test_a_held_rippling_reply_is_not_a_reply(): void
    {
        $data = $this->createChaseCandidate();
        $this->holdReply($data, 'held');

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased'], 'the poster has not seen a held reply, so there is nothing to chase');
    }

    public function test_a_released_rippling_reply_counts_as_a_reply(): void
    {
        $data = $this->createChaseCandidate();
        $this->holdReply($data, 'released');

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['chased']);
    }

    public function test_constants(): void
    {
        $this->assertEquals(90, ChaseUpService::LOOKBACK_DAYS);
        $this->assertEquals([
            'offer' => 3,
            'wanted' => 7,
            'max' => 5,
            'chaseups' => 5,
        ], ChaseUpService::DEFAULT_REPOSTS);
    }
}
