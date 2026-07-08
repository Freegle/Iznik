<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
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
        ?object $group = null,
        int $hoursOld = 500,
        int $autoreposts = 5,
        int $replyHoursAgo = 200,
    ): array {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $user ?? $this->createTestUser();
        $group = $group ?? $this->createTestGroup();

        $this->createMembership($user, $group, [
            'added' => now()->subDays(60),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        // Set arrival old enough and autoreposts at max.
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
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
            'group' => $group,
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

        // Verify lastchaseup was set per-group.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertNotNull($mg->lastchaseup);
    }

    public function test_notify_languishing_ignores_a_rippling_held_reply(): void
    {
        // A languishing post has no recent (deliverable) chat. A rippling-HELD reply hasn't reached
        // the poster, so it must NOT count as recent activity that suppresses the languishing chase.
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user   = $this->createTestUser();
        $group  = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subDays(60)]);

        $message = $this->createTestMessage($user, $group, [
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source'   => Message::SOURCE_PLATFORM,
        ]);
        // Arrival within the languishing window (31d..48h ago); autoreposts past the max (5);
        // msgtype set because notifyLanguishing filters on messages_groups.msgtype (Offer/Wanted).
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['arrival' => now()->subDays(10), 'autoreposts' => 6, 'msgtype' => Message::TYPE_OFFER]);

        // A RECENT reply (within 48h) that is HELD — must not suppress the languishing chase.
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $replier);
        $reply = $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $message->id,
            'date'     => now()->subHours(1),
        ]);
        DB::table('rippling_held_replies')->insert([
            'chatid'        => $room->id,
            'chatmsgid'     => $reply->id,
            'msgid'         => $message->id,
            'replieruserid' => $replier->id,
            'source'        => 'web',
            'lat'           => 51.5,
            'lng'           => -0.1,
            'status'        => 'held',
            'created_at'    => now(),
        ]);

        // Held: the post is still languishing (the reply is invisible to the poster).
        $this->assertEquals(1, $this->service->notifyLanguishing(dryRun: true),
            'a held reply must not suppress the languishing chase');

        // Released: it now counts as recent activity, so the post is no longer languishing.
        DB::table('rippling_held_replies')->where('chatmsgid', $reply->id)
            ->update(['status' => 'released', 'releasedat' => now()]);
        $this->assertEquals(0, $this->service->notifyLanguishing(dryRun: true),
            'once released, the recent reply suppresses the languishing chase');
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $data = $this->createChaseCandidate();

        $stats = $this->service->process(dryRun: true);

        $this->assertEquals(1, $stats['chased']);

        // lastchaseup should still be null.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertNull($mg->lastchaseup);
    }

    public function test_skips_message_without_chat_reply(): void
    {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subDays(60),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'fromaddr' => 'test@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
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
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subDays(60),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'fromaddr' => 'test@external.com',
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
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

    public function test_skips_closed_group(): void
    {
        $group = $this->createTestGroup([
            'settings' => ['closed' => true],
        ]);
        $data = $this->createChaseCandidate(group: $group);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
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
        $relatedMsg = $this->createTestMessage($user2, $data['group']);
        DB::table('messages_related')->insert([
            'id1' => $data['message']->id,
            'id2' => $relatedMsg->id,
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased']);
    }

    public function test_crosspost_chased_up_once_not_per_group(): void
    {
        // A message cross-posted to two groups, both eligible for chase-up. The
        // chase-up is about the item's global outcome, so the poster must get ONE
        // email, not one per group.
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->createMembership($user, $groupA, ['added' => now()->subDays(60)]);
        $this->createMembership($user, $groupB, ['added' => now()->subDays(60)]);

        $message = $this->createTestMessage($user, $groupA, [
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        // Group A row eligible (max reposts reached, old arrival).
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $groupA->id)
            ->update(['arrival' => now()->subHours(500), 'autoreposts' => 5]);

        // Cross-post to group B, also eligible.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subHours(500),
        ]);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $groupB->id)
            ->update(['autoreposts' => 5]);

        // One chat reply about the item, old enough to trigger a chase-up.
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $replier);
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $message->id,
            'date' => now()->subHours(200),
        ]);

        $stats = $this->service->process();

        // Exactly one chase-up for the single physical item, despite two groups.
        $this->assertEquals(1, $stats['chased'], 'cross-posted item must be chased up once, not once per group');

        // lastchaseup stamped on BOTH groups so neither re-fires on the next run.
        $rows = DB::table('messages_groups')->where('msgid', $message->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertNotNull($r->lastchaseup, 'lastchaseup must be set on every group of the item');
        }
    }

    /**
     * Rippling-out: a post eligible for chase-up on its home group that has also rippled
     * into another group is chased up ONCE (anchored to the home posting), and the stamp
     * lands on every group so neither re-fires.
     */
    public function test_rippled_item_chased_up_once_from_home(): void
    {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $this->createTestUser();
        $home = $this->createTestGroup();
        $rippled = $this->createTestGroup();
        $this->createMembership($user, $home, ['added' => now()->subDays(60)]);
        $this->createMembership($user, $rippled, ['added' => now()->subDays(60)]);

        $message = $this->createTestMessage($user, $home, [
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        // Home posting: max reposts reached, old arrival, native (rippled_in=0).
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $home->id)
            ->update(['arrival' => now()->subHours(500), 'autoreposts' => 5, 'rippled_in' => 0]);

        // Rippled-in posting: also "max reposts", but must NOT initiate a chase-up itself.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $rippled->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subHours(500),
        ]);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $rippled->id)
            ->update(['autoreposts' => 5, 'rippled_in' => 1]);

        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $replier);
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $message->id,
            'date' => now()->subHours(200),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['chased'], 'chase-up anchored to home posting: exactly one');
        $rows = DB::table('messages_groups')->where('msgid', $message->id)->get();
        foreach ($rows as $r) {
            $this->assertNotNull($r->lastchaseup, 'lastchaseup must be set on every group of the item');
        }
    }

    /**
     * Rippling-out (defensive): a posting that exists ONLY as a rippled-in row (its home
     * posting has gone) must not initiate a chase-up — that is the home posting's job.
     */
    public function test_rippled_only_item_is_not_chased_up(): void
    {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $this->createTestUser();
        $rippled = $this->createTestGroup();
        $this->createMembership($user, $rippled, ['added' => now()->subDays(60)]);

        $message = $this->createTestMessage($user, $rippled, [
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $rippled->id)
            ->update(['arrival' => now()->subHours(500), 'autoreposts' => 5, 'rippled_in' => 1]);

        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $replier);
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $message->id,
            'date' => now()->subHours(200),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['chased'], 'a rippled-only posting must not be chased up');
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
