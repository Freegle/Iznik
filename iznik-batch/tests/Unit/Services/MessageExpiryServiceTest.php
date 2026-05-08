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

    public function test_process_expired_from_spatial_index(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create a spatial index entry.
        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // Verify outcome was created.
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);

        // Verify spatial entry was deleted.
        $this->assertDatabaseMissing('messages_spatial', [
            'msgid' => $message->id,
        ]);
    }

    public function test_process_expired_from_spatial_index_skips_with_existing_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create existing outcome.
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        // Create a spatial index entry.
        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // Verify no new expired outcome was created (still just the TAKEN one).
        $this->assertEquals(1, MessageOutcome::where('msgid', $message->id)->count());
        $this->assertEquals(MessageOutcome::OUTCOME_TAKEN, MessageOutcome::where('msgid', $message->id)->first()->outcome);
    }

    public function test_expire_lookback_days_constant(): void
    {
        $this->assertEquals(90, MessageExpiryService::EXPIRE_LOOKBACK_DAYS);
    }

    public function test_process_expired_from_spatial_index_logs_progress(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create 100 messages with spatial index entries to trigger progress logging.
        for ($i = 0; $i < 100; $i++) {
            $message = $this->createTestMessage($user, $group, [
                'subject' => "OFFER: Test Item $i (TestLocation)",
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
