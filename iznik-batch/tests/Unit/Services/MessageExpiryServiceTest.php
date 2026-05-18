<?php

namespace Tests\Unit\Services;

use App\Mail\Message\DeadlineReached;
use App\Models\Message;
use App\Models\MessageOutcome;
use App\Services\MessageExpiryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageExpiryServiceTest extends TestCase
{
    protected MessageExpiryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure feature flag is enabled for tests.
        config(['freegle.mail.enabled_types' => config('freegle.mail.enabled_types') . ',MessageExpiry']);
        $this->service = new MessageExpiryService();
    }

    public function test_process_deadline_expired_with_no_messages(): void
    {
        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_deadline_expired_sends_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to yesterday.
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(1, $stats['emails_sent']);
        $this->assertEquals(0, $stats['errors']);

        Mail::assertSent(DeadlineReached::class);

        // Verify outcome was created.
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);
    }

    public function test_process_deadline_expired_skips_messages_with_outcome(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to yesterday and add an outcome.
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_process_deadline_expired_skips_future_deadline(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to tomorrow.
        $message->deadline = now()->addDays(1)->format('Y-m-d');
        $message->save();

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_process_deadline_expired_skips_user_without_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to yesterday.
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        // Remove user's email.
        DB::table('users_emails')->where('userid', $user->id)->delete();

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_multi_group_message_processed_once(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $this->createMembership($user, $group1);
        $this->createMembership($user, $group2);

        $message = $this->createTestMessage($user, $group1);

        // Add the same message to a second group.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => 'Approved',
            'arrival' => now(),
        ]);

        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        $stats = $this->service->processDeadlineExpired();

        // Message in 2 groups must only be processed once.
        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(1, $stats['emails_sent']);
        $this->assertEquals(1, MessageOutcome::where('msgid', $message->id)->count());

        Mail::assertSent(DeadlineReached::class, 1);
    }

    public function test_expiry_clears_messages_outcomes_intended(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        // Create a pre-existing intended outcome.
        DB::table('messages_outcomes_intended')->insert([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['processed']);

        // Intended outcome must be cleared.
        $this->assertDatabaseMissing('messages_outcomes_intended', ['msgid' => $message->id]);

        // Real outcome must be created.
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);
    }

    public function test_process_expired_from_spatial_index_acts_on_already_expired(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Existing EXPIRED outcome (e.g. the deadline-expiry path ran earlier
        // in the same scheduled batch).
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
            'timestamp' => now()->subHour(),
        ]);

        // Spatial index entry waiting for cleanup.
        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // V1 mark() does DELETE FROM messages_outcomes before INSERT, so the
        // Expired row is replaced by the Withdrawn "Auto-expired" — exactly one
        // outcome row should remain. Leaving both would cause the Go outcome
        // handler to return 409 when the owner later tries to mark Taken.
        $this->assertEquals(
            1,
            MessageOutcome::where('msgid', $message->id)->count(),
            'processMessageExpiry must replace existing outcomes, not stack them'
        );
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
        ]);
        $this->assertDatabaseMissing('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);

        // Spatial entry deleted.
        $this->assertDatabaseMissing('messages_spatial', [
            'msgid' => $message->id,
        ]);
    }

    /**
     * Regression: a message that hits the deadline-expiry path and then the
     * spatial-index expiry path in the same scheduled run must end up with
     * exactly one outcome row. Previously both paths inserted without
     * deleting first, leaving duplicate (Expired + Withdrawn) rows that
     * caused the Go API to 409 when the owner tried to mark the post Taken.
     */
    public function test_deadline_then_spatial_expiry_leaves_single_outcome(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        // Spatial index entry — present so the second-phase cleanup picks the
        // message up after the deadline path marks it Expired.
        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        // Phase 1 — deadline expiry inserts the EXPIRED outcome.
        $stats = $this->service->processDeadlineExpired();
        $this->assertEquals(1, $stats['processed']);

        // Phase 2 — spatial-index expiry runs next; it finds the message via
        // the "EXPIRED outcome exists" branch of getExpiredCandidates and must
        // replace the Expired row rather than appending Withdrawn.
        $count = $this->service->processExpiredFromSpatialIndex();
        $this->assertEquals(1, $count);

        $this->assertEquals(
            1,
            MessageOutcome::where('msgid', $message->id)->count(),
            'a message hitting both expiry paths in one run must keep a single outcome row'
        );
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
        ]);
    }

    public function test_process_expired_from_spatial_index_no_op_for_fresh_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Default group has no custom settings → OFFER expiretime = GREATEST(90, 3*(10+1)) = 90.
        // A message with today's arrival is 0 days old, so the virtual-expiry filter doesn't fire.
        $message = $this->createTestMessage($user, $group);

        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('messages_outcomes', [
            'msgid' => $message->id,
        ]);
        $this->assertDatabaseHas('messages_spatial', [
            'msgid' => $message->id,
        ]);
    }

    public function test_process_expired_from_spatial_index_virtual_expiry_by_age(): void
    {
        $user = $this->createTestUser();

        // Group with maxagetoshow=30 and reposts that yield 3*(5+1)=18 → expiretime = 30.
        $group = $this->createTestGroup([
            'settings' => [
                'maxagetoshow' => 30,
                'reposts' => ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5],
            ],
        ]);
        $this->createMembership($user, $group);

        // Group arrival 31 days ago → past expiretime.
        $arrival = now()->subDays(31);
        $message = $this->createTestMessage($user, $group, ['arrival' => $arrival]);

        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // V1 inserts only an OUTCOME_WITHDRAWN "Auto-expired"; the virtual EXPIRED is never persisted.
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
        ]);
        $this->assertDatabaseMissing('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);
        $this->assertDatabaseMissing('messages_spatial', [
            'msgid' => $message->id,
        ]);
    }

    public function test_process_expired_from_spatial_index_respects_reposts_when_maxagetoshow_zero(): void
    {
        $user = $this->createTestUser();

        // maxagetoshow=0 means reposts-based expiry alone: 4*(5+1) = 24 days for Offer.
        $group = $this->createTestGroup([
            'settings' => [
                'maxagetoshow' => 0,
                'reposts' => ['offer' => 4, 'wanted' => 7, 'max' => 5, 'chaseups' => 5],
            ],
        ]);
        $this->createMembership($user, $group);

        $youngMsg = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'arrival' => now()->subDays(20),
        ]);
        $oldMsg = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'arrival' => now()->subDays(25),
        ]);

        foreach ([$youngMsg, $oldMsg] as $m) {
            DB::table('messages_spatial')->insert([
                'msgid' => $m->id,
                'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
                'successful' => 0,
            ]);
        }

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('messages_outcomes', ['msgid' => $youngMsg->id]);
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $oldMsg->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
        ]);
    }

    public function test_process_expired_from_spatial_index_skips_when_recent_chat_reply(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => [
                'maxagetoshow' => 30,
                'reposts' => ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5],
            ],
        ]);
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, ['arrival' => now()->subDays(31)]);

        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        // A chat room with a recent reply (last 6 days) referencing this msg.
        $chatid = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $user->id,
            'user2' => $other->id,
            'latestmessage' => now(),
        ]);
        DB::table('chat_messages')->insert([
            'chatid' => $chatid,
            'userid' => $other->id,
            'refmsgid' => $message->id,
            'type' => 'Default',
            'message' => 'Interested!',
            'date' => now()->subDays(1),
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('messages_outcomes', ['msgid' => $message->id]);
        $this->assertDatabaseHas('messages_spatial', ['msgid' => $message->id]);
    }

    public function test_process_expired_from_spatial_index_skips_when_promised(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => [
                'maxagetoshow' => 30,
                'reposts' => ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5],
            ],
        ]);
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, ['arrival' => now()->subDays(31)]);

        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        DB::table('messages_promises')->insert([
            'msgid' => $message->id,
            'userid' => $other->id,
            'promisedat' => now(),
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('messages_outcomes', ['msgid' => $message->id]);
        $this->assertDatabaseHas('messages_spatial', ['msgid' => $message->id]);
    }

    public function test_process_expired_from_spatial_index_dry_run_writes_nothing(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => [
                'maxagetoshow' => 30,
                'reposts' => ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5],
            ],
        ]);
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, ['arrival' => now()->subDays(31)]);

        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex(true);

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('messages_outcomes', ['msgid' => $message->id]);
        $this->assertDatabaseHas('messages_spatial', ['msgid' => $message->id]);
    }

    public function test_process_expired_from_spatial_index_skips_taken_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Existing TAKEN outcome (not EXPIRED) — V1 also no-op.
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(0, $count);
        $this->assertEquals(1, MessageOutcome::where('msgid', $message->id)->count());
        $this->assertEquals(MessageOutcome::OUTCOME_TAKEN, MessageOutcome::where('msgid', $message->id)->first()->outcome);
    }

    public function test_expire_lookback_days_constant(): void
    {
        $this->assertEquals(90, MessageExpiryService::EXPIRE_LOOKBACK_DAYS);
    }

    /**
     * Regression: WANTED posts older than maxagetoshow (90 days) must expire
     * at the same threshold as OFFER posts.
     *
     * Bug: getExpiredCandidates() computes the WANTED expiretime as
     *   GREATEST(maxagetoshow=90, reposts.wanted=14 * (max=10+1)) = GREATEST(90,154) = 154 days
     * so a 95-day-old WANTED post is never returned as a candidate and is never
     * auto-expired, even though it is past the maxagetoshow threshold that
     * governs display and OFFER expiry alike.
     */
    public function test_wanted_post_expires_after_maxagetoshow_threshold(): void
    {
        $user = $this->createTestUser();
        // Default group (no custom settings) → maxagetoshow=90, reposts.offer=3,
        // reposts.wanted=14, max=10.
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Both posts are 95 days old — past maxagetoshow (90) but before the
        // inflated WANTED threshold (14*11 = 154).
        $arrival = now()->subDays(95);

        $offer = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'arrival' => $arrival,
        ]);
        $wanted = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Some Item (TestLocation)',
            'arrival' => $arrival,
        ]);

        foreach ([$offer, $wanted] as $m) {
            DB::table('messages_spatial')->insert([
                'msgid' => $m->id,
                'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
                'successful' => 0,
            ]);
        }

        $count = $this->service->processExpiredFromSpatialIndex();

        // Both OFFER and WANTED must be expired after crossing maxagetoshow.
        $this->assertEquals(2, $count);

        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $offer->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
        ]);
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $wanted->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
        ]);
        $this->assertDatabaseMissing('messages_spatial', ['msgid' => $offer->id]);
        $this->assertDatabaseMissing('messages_spatial', ['msgid' => $wanted->id]);
    }

    public function test_process_expired_from_spatial_index_logs_progress(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create 100 messages with EXISTING EXPIRED outcomes + spatial entries
        // (so the V1-mirroring filter actually picks them up).
        for ($i = 0; $i < 100; $i++) {
            $message = $this->createTestMessage($user, $group, [
                'subject' => "OFFER: Test Item $i (TestLocation)",
            ]);

            MessageOutcome::create([
                'msgid' => $message->id,
                'outcome' => MessageOutcome::OUTCOME_EXPIRED,
                'timestamp' => now()->subHour(),
            ]);

            DB::table('messages_spatial')->insert([
                'msgid' => $message->id,
                'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
                'successful' => 0,
            ]);
        }

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(100, $count);
    }

}
