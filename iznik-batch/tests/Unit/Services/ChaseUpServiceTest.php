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
     * has a chat reply old enough, no outcome, no related messages.
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

    public function test_skips_message_with_related_messages(): void
    {
        $data = $this->createChaseCandidate();

        // Create a related message link.
        $user2 = $this->createTestUser();
        $relatedMsg = $this->createTestMessage($user2);
        DB::table('messages_related')->insert([
            'id1' => $data['message']->id,
            'id2' => $relatedMsg->id,
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
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
