<?php

namespace Tests\Unit\Queue;

use App\Mail\Chat\ReferToSupportMail;
use App\Mail\Donation\DonateExternalMail;
use App\Mail\Newsfeed\ChitchatReportMail;
use App\Mail\Session\ForgotPasswordMail;
use App\Mail\Session\MergeOfferMail;
use App\Mail\Session\UnsubscribeConfirmMail;
use App\Mail\Session\VerifyEmailMail;
use App\Mail\Message\ModStdMessageMail;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessBackgroundTasksCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the background_tasks table exists in the test database.
        DB::statement('CREATE TABLE IF NOT EXISTS background_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_type VARCHAR(50) NOT NULL,
            data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            attempts INT UNSIGNED DEFAULT 0,
            INDEX idx_task_type (task_type),
            INDEX idx_pending (processed_at, created_at)
        )');
    }

    protected function tearDown(): void
    {
        // Clean up any tasks created during tests.
        DB::table('background_tasks')->truncate();
        parent::tearDown();
    }

    public function test_processes_push_notify_group_mods_task(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Owner']);

        // Insert a task simulating what Go would insert.
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_group_mods',
            'data' => json_encode(['group_id' => $group->id]),
            'created_at' => now(),
        ]);

        // Mock the push service - Firebase won't be configured in test.
        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(1);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
        $this->assertNull($task->failed_at);
        $this->assertEquals(1, $task->attempts);
    }

    public function test_processes_email_chitchat_report_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_chitchat_report',
            'data' => json_encode([
                'user_id' => 12345,
                'user_name' => 'Test Reporter',
                'user_email' => 'reporter@test.com',
                'newsfeed_id' => 67890,
                'reason' => 'Inappropriate content',
            ]),
            'created_at' => now(),
        ]);

        // Mock push service (not needed for this test but required by command).
        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent.
        Mail::assertSent(ChitchatReportMail::class, function (ChitchatReportMail $mail) {
            return $mail->reporterName === 'Test Reporter'
                && $mail->reporterId === 12345
                && $mail->reporterEmail === 'reporter@test.com'
                && $mail->newsfeedId === 67890
                && $mail->reason === 'Inappropriate content';
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_skips_already_processed_tasks(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_group_mods',
            'data' => json_encode(['group_id' => 1]),
            'created_at' => now(),
            'processed_at' => now(),  // Already processed.
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldNotReceive('notifyGroupMods');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();
    }

    public function test_marks_failed_after_max_attempts(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'unknown_task_type',
            'data' => json_encode(['test' => TRUE]),
            'created_at' => now(),
            'attempts' => 2,  // Already tried twice.
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should be marked as permanently failed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->failed_at);
        $this->assertNotNull($task->error_message);
        $this->assertStringContains('Unknown task type', $task->error_message);
    }

    public function test_handles_missing_required_fields(): void
    {
        // Email report with missing required fields.
        DB::table('background_tasks')->insert([
            'task_type' => 'email_chitchat_report',
            'data' => json_encode(['user_id' => 123]),  // Missing user_name, user_email, etc.
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should have recorded the error but not permanently failed yet (attempts < 3).
        $task = DB::table('background_tasks')->first();
        $this->assertNull($task->processed_at);
        $this->assertNotNull($task->error_message);
        $this->assertEquals(1, $task->attempts);
    }

    public function test_processes_multiple_tasks_in_order(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

        // Insert tasks in order.
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_group_mods',
            'data' => json_encode(['group_id' => $group->id]),
            'created_at' => now()->subSeconds(2),
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_chitchat_report',
            'data' => json_encode([
                'user_id' => 1,
                'user_name' => 'Reporter',
                'user_email' => 'test@test.com',
                'newsfeed_id' => 100,
                'reason' => 'Test reason',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Both should be processed.
        $tasks = DB::table('background_tasks')->get();
        $this->assertCount(2, $tasks);
        foreach ($tasks as $task) {
            $this->assertNotNull($task->processed_at);
        }
    }

    public function test_processes_email_donate_external_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_donate_external',
            'data' => json_encode([
                'user_id' => 54321,
                'user_name' => 'Generous Donor',
                'user_email' => 'donor@test.com',
                'amount' => 25.50,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct data and defaults to external source.
        Mail::assertSent(DonateExternalMail::class, function (DonateExternalMail $mail) {
            return $mail->userName === 'Generous Donor'
                && $mail->userId === 54321
                && $mail->userEmail === 'donor@test.com'
                && $mail->amount === 25.50
                && $mail->source === DonateExternalMail::SOURCE_EXTERNAL;
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_processes_email_donate_paypal_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_donate_external',
            'data' => json_encode([
                'user_id' => 111,
                'user_name' => 'PayPal Donor',
                'user_email' => 'paypal@test.com',
                'amount' => 30.00,
                'source' => 'paypal',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertSent(DonateExternalMail::class, function (DonateExternalMail $mail) {
            return $mail->source === DonateExternalMail::SOURCE_PAYPAL
                && $mail->getChannelPhrase() === 'PayPal Donate'
                && str_contains($mail->envelope()->subject, 'via PayPal Donate');
        });
    }

    public function test_processes_email_donate_stripe_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_donate_external',
            'data' => json_encode([
                'user_id' => 222,
                'user_name' => 'Stripe Donor',
                'user_email' => 'stripe@test.com',
                'amount' => 40.00,
                'source' => 'stripe',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertSent(DonateExternalMail::class, function (DonateExternalMail $mail) {
            return $mail->source === DonateExternalMail::SOURCE_STRIPE
                && $mail->getChannelPhrase() === 'Stripe'
                && str_contains($mail->envelope()->subject, 'via Stripe');
        });
    }

    public function test_email_donate_external_fails_with_missing_fields(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'email_donate_external',
            'data' => json_encode(['user_id' => 123]),  // Missing user_name, user_email, amount.
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should have recorded the error.
        $task = DB::table('background_tasks')->first();
        $this->assertNull($task->processed_at);
        $this->assertNotNull($task->error_message);
        $this->assertStringContains('email_donate_external requires', $task->error_message);
    }

    public function test_processes_email_forgot_password_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_forgot_password',
            'data' => json_encode([
                'user_id' => 11111,
                'email' => 'forgetful@test.com',
                'reset_url' => 'https://www.ilovefreegle.org/settings?u=11111&k=abc123&src=forgotpass',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct data.
        Mail::assertSent(ForgotPasswordMail::class, function (ForgotPasswordMail $mail) {
            return $mail->userId === 11111
                && $mail->email === 'forgetful@test.com'
                && $mail->resetUrl === 'https://www.ilovefreegle.org/settings?u=11111&k=abc123&src=forgotpass';
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_processes_email_unsubscribe_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_unsubscribe',
            'data' => json_encode([
                'user_id' => 22222,
                'email' => 'leaving@test.com',
                'unsub_url' => 'https://www.ilovefreegle.org/unsubscribe/22222?u=22222&k=def456&confirm=1',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct data.
        Mail::assertSent(UnsubscribeConfirmMail::class, function (UnsubscribeConfirmMail $mail) {
            return $mail->userId === 22222
                && $mail->email === 'leaving@test.com'
                && $mail->unsubUrl === 'https://www.ilovefreegle.org/unsubscribe/22222?u=22222&k=def456&confirm=1';
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_exits_after_max_iterations(): void
    {
        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 3,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Command should have exited cleanly after 3 iterations.
    }

    public function test_mod_stdmsg_reject_sends_email_from_group_volunteers(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Wendy Moderator']);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'WANTED: Something (Test AB1)',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Rejected',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'MODERATOR MESSAGE -: WANTED: Something (Test AB1)',
                'body' => 'Dear member, please repost with more detail.',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct from address and content.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($group, $mod) {
            $this->assertEquals('Wendy Moderator', $mail->modName);
            $this->assertEquals($group->nameshort, $mail->groupNameShort);
            $this->assertEquals('MODERATOR MESSAGE -: WANTED: Something (Test AB1)', $mail->stdSubject);
            $this->assertEquals('Dear member, please repost with more detail.', $mail->stdBody);
            return TRUE;
        });

        // Verify log entry was created.
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Rejected')
            ->first();
        $this->assertNotNull($log, 'Rejected log entry should be created');
        $this->assertEquals($mod->id, $log->byuser);

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_mod_stdmsg_approve_without_content_skips_email(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $mod = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_approved',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => '',
                'body' => '',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // No email should be sent for empty stdmsg.
        Mail::assertNothingSent();

        // But the log entry should always be created (even without email content).
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Approved')
            ->first();
        $this->assertNotNull($log, 'Approved log entry should be created even without email content');

        // And the task should be marked as processed (not failed).
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_mod_stdmsg_falls_back_to_messages_groups_for_groupid(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Test Mod']);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'WANTED: Widget',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // Queue task WITHOUT groupid (old Go API format).
        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_reply',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'subject' => 'Re: WANTED: Widget',
                'body' => 'Thanks for posting!',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($group) {
            $this->assertEquals($group->nameshort, $mail->groupNameShort);
            return TRUE;
        });

        // Verify reply log entry was created.
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Replied')
            ->first();
        $this->assertNotNull($log, 'Replied log entry should be created');

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_mod_stdmsg_creates_chat_room_and_message(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Chat Mod']);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Chat test',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Rejected',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Rejection notice',
                'body' => 'Please repost.',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify a User2Mod chat room was created.
        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $poster->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom, 'User2Mod chat room should be created');

        // Verify a chat message was created.
        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $mod->id)
            ->where('type', 'ModMail')
            ->first();
        $this->assertNotNull($chatMsg, 'ModMail chat message should be created');
        $this->assertEquals($msgId, $chatMsg->refmsgid);
        $this->assertStringContains('Rejection notice', $chatMsg->message);
        $this->assertStringContains('Please repost.', $chatMsg->message);
    }

    public function test_message_outcome_logs_and_notifies_interested_users(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // Create a User2User chat room where the replier expressed interest.
        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $poster->id,
            'user2' => $replier->id,
        ]);
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $replier->id,
            'message' => 'Is this still available?',
            'type' => 'Interested',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'message_outcome',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $poster->id,
                'outcome' => 'Taken',
                'happiness' => 'Happy',
                'comment' => 'Great experience',
                'userid' => 0,
                'message' => 'Sorry, this has now been taken.',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify log entry was created.
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Outcome')
            ->first();
        $this->assertNotNull($log, 'Outcome log entry should be created');
        $this->assertEquals($poster->id, $log->user);
        $this->assertEquals($group->id, $log->groupid);
        $this->assertStringContains('Taken', $log->text);

        // Verify the interested replier got a Completed chat message.
        $completedMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoomId)
            ->where('type', 'Completed')
            ->first();
        $this->assertNotNull($completedMsg, 'Completed chat message should be created');
        $this->assertEquals($poster->id, $completedMsg->userid);
        $this->assertEquals($msgId, $completedMsg->refmsgid);
        $this->assertEquals('Sorry, this has now been taken.', $completedMsg->message);

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_message_outcome_skips_message_for_unpromised_chats(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $poster->id,
            'user2' => $replier->id,
        ]);
        // Interested message.
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $replier->id,
            'message' => 'I want this',
            'type' => 'Interested',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);
        // Reneged (unpromised) message — poster changed their mind.
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $poster->id,
            'message' => null,
            'type' => 'Reneged',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'message_outcome',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $poster->id,
                'outcome' => 'Taken',
                'happiness' => '',
                'comment' => '',
                'userid' => 0,
                'message' => 'Sorry, this has been taken.',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // The Completed message should have null message body (unpromised).
        $completedMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoomId)
            ->where('type', 'Completed')
            ->first();
        $this->assertNotNull($completedMsg, 'Completed chat message should be created');
        $this->assertNull($completedMsg->message, 'Message should be null for unpromised chat');
    }

    public function test_message_outcome_excludes_user_who_got_item(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $taker = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $poster->id,
            'user2' => $taker->id,
        ]);
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $taker->id,
            'message' => 'I want this',
            'type' => 'Interested',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);

        // Record that this user actually got the item.
        DB::table('messages_by')->insert([
            'msgid' => $msgId,
            'userid' => $taker->id,
            'count' => 1,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'message_outcome',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $poster->id,
                'outcome' => 'Taken',
                'happiness' => '',
                'comment' => '',
                'userid' => $taker->id,
                'message' => 'Sorry, taken.',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // No Completed message should be created — the taker already got it.
        $completedMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoomId)
            ->where('type', 'Completed')
            ->first();
        $this->assertNull($completedMsg, 'Should not notify user who got the item');

        // But the log should still be there.
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Outcome')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_mod_stdmsg_for_member_sends_email_and_creates_chat(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $memberEmail = $this->createTestUserEmail($member, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Test Moderator']);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Location Required please',
                'body' => 'Hello, can you please advise your postcode?',
                'action' => 'Leave Approved Member',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($mod) {
            $this->assertEquals('Test Moderator', $mail->modName);
            $this->assertEquals('Location Required please', $mail->stdSubject);
            $this->assertEquals('Hello, can you please advise your postcode?', $mail->stdBody);
            return TRUE;
        });

        // Verify task was processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);

        // Verify chat room and message were created.
        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $member->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom, 'Chat room should be created');

        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $mod->id)
            ->where('type', 'ModMail')
            ->first();
        $this->assertNotNull($chatMsg, 'Chat message should be created');
        $this->assertStringContains('Location Required please', $chatMsg->message);
    }

    public function test_mod_stdmsg_for_tn_user_sends_email_to_tn_proxy(): void
    {
        // Regression: on 2026-04-03 a stdmsg sent to TN user 41825411 was never received.
        // The member's only emails are @user.trashnothing.com proxies. Verify the batch
        // processor sends to the TN proxy address and does not silently skip the member.
        Mail::fake();

        $group = $this->createTestGroup();
        $tnEmail = 'user' . uniqid() . '@user.trashnothing.com';
        $member = $this->createTestUser(['email_preferred' => $tnEmail]);
        $mod = $this->createTestUser(['fullname' => 'Test Moderator']);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Membership of Hertford Freegle',
                'body' => 'Dear member, please update your location.',
                'stdmsgid' => 219508,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Email must be sent to the TN proxy — not skipped because the address is external.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($tnEmail) {
            return collect($mail->to)->pluck('address')->contains($tnEmail);
        });

        // Chat message must be created so the conversation appears in modtools.
        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $member->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom, 'Chat room should be created for TN user');

        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $mod->id)
            ->where('type', 'ModMail')
            ->first();
        $this->assertNotNull($chatMsg, 'ModMail chat message should be created for TN user');
    }

    public function test_mod_stdmsg_for_member_sets_lastmsgemailed_after_direct_send(): void
    {
        // Regression: on 2026-04-03 the notification daemon found lastmsgemailed equal to
        // the new ModMail message ID and skipped emailing the member — they got nothing.
        // After handleModStdMessageForMember sends the email and creates the chat message,
        // it must update chat_roster.lastmsgemailed to that message ID so the notification
        // daemon (NotifyUser2ModCommand) does not send a duplicate and then mark it done.
        // Without this update the daemon would either:
        //   (a) send a duplicate notification (double email to member), or
        //   (b) find lastmsgemailed already set by some other path and skip sending entirely.
        Mail::fake();

        $group = $this->createTestGroup();
        $tnEmail = 'user' . uniqid() . '@user.trashnothing.com';
        $member = $this->createTestUser(['email_preferred' => $tnEmail]);
        $mod = $this->createTestUser(['fullname' => 'Test Moderator']);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Membership of Hertford Freegle',
                'body' => 'Dear member, please update your location.',
                'stdmsgid' => 219508,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $member->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom);

        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $mod->id)
            ->where('type', 'ModMail')
            ->first();
        $this->assertNotNull($chatMsg);

        // After the direct email send, lastmsgemailed MUST equal the new message ID.
        // This prevents NotifyUser2ModCommand from treating it as unnotified and either
        // sending a duplicate or (if something else already set it) silently skipping the member.
        $roster = DB::table('chat_roster')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $member->id)
            ->first();
        $this->assertNotNull($roster, 'Member must have a roster entry');
        $this->assertEquals(
            $chatMsg->id,
            $roster->lastmsgemailed,
            'lastmsgemailed must be set to the ModMail message ID after direct send, ' .
            'otherwise the notification daemon will attempt to re-send'
        );
    }

    public function test_mod_stdmsg_only_marks_member_as_emailed_not_other_mods(): void
    {
        // After handleModStdMessageForMember runs:
        // - The member's lastmsgemailed must be set (direct email already sent — daemon must not duplicate).
        // - Other mods' lastmsgemailed must NOT be pre-set, so the notification daemon CAN notify them
        //   that a stdmsg was sent (V1 parity: mods see ModMail messages in their chat queue).
        Mail::fake();

        $group = $this->createTestGroup();
        $tnEmail = 'user' . uniqid() . '@user.trashnothing.com';
        $member = $this->createTestUser(['email_preferred' => $tnEmail]);
        $mod = $this->createTestUser(['fullname' => 'Sending Mod']);
        $otherMod = $this->createTestUser(['fullname' => 'Other Mod']);

        $this->createMembership($otherMod, $group, ['role' => 'Moderator']);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Membership of Hertford Freegle',
                'body' => 'Dear member, please update your location.',
                'stdmsgid' => 219508,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $member->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom);

        // Member's lastmsgemailed must be set — direct email already sent.
        $memberRoster = DB::table('chat_roster')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $member->id)
            ->first();
        $this->assertNotNull($memberRoster->lastmsgemailed, 'Member lastmsgemailed must be set');

        // Other mod's roster must NOT have lastmsgemailed pre-set — the notification daemon
        // should be able to notify them that a stdmsg was sent to the member.
        $otherModRoster = DB::table('chat_roster')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $otherMod->id)
            ->first();
        if ($otherModRoster) {
            $this->assertNull(
                $otherModRoster->lastmsgemailed,
                'Other mod lastmsgemailed must not be pre-set — daemon must be able to notify them'
            );
        }
    }

    public function test_mod_stdmsg_for_member_creates_log_and_modmails_record(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createTestUserEmail($member, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Test Moderator']);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Please read our group rules',
                'body' => 'Welcome to the group. Please read our rules.',
                'action' => 'Leave Approved Member',
                'stdmsgid' => 42,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify a log entry was created (so modmail appears in mod logs).
        // V1 parity: modmails are logged as User/Mailed (not Message/Replied).
        $logEntry = DB::table('logs')
            ->where('type', 'User')
            ->where('subtype', 'Mailed')
            ->where('byuser', $mod->id)
            ->where('user', $member->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertNotNull($logEntry, 'Log entry should be created so modmail appears in logs');
        $this->assertEquals('Please read our group rules', $logEntry->text);
        $this->assertEquals(42, $logEntry->stdmsgid);

        // users_modmails is no longer populated here — the syncModMailCounts cron job
        // populates it by scanning the logs table.
    }

    public function test_mod_stdmsg_for_member_without_content_skips_email(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => '',
                'body' => '',
                'action' => 'Leave Approved Member',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertNothingSent();

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_email_membership_approved_task_type_no_longer_exists(): void
    {
        // email_membership_approved was removed — membership approve now queues
        // email_mod_stdmsg directly (when content is provided) or nothing (no content).
        DB::table('background_tasks')->insert([
            'task_type' => 'email_membership_approved',
            'data' => json_encode([
                'userid' => 1,
                'byuser' => 2,
                'groupid' => 3,
                'subject' => 'Welcome',
                'body' => 'You have been approved.',
            ]),
            'created_at' => now(),
            'attempts' => 2,  // Already tried twice — next attempt marks as failed.
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should fail with "Unknown task type" on the third (final) attempt.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->failed_at, 'Unknown task type should be permanently failed');
        $this->assertStringContains('Unknown task type', $task->error_message ?? '');
    }

    public function test_email_membership_rejected_task_type_no_longer_exists(): void
    {
        // email_membership_rejected was removed — membership reject now queues
        // email_mod_stdmsg directly (when content is provided) or nothing (no content).
        DB::table('background_tasks')->insert([
            'task_type' => 'email_membership_rejected',
            'data' => json_encode([
                'userid' => 1,
                'byuser' => 2,
                'groupid' => 3,
                'subject' => 'Sorry',
                'body' => 'Your application was rejected.',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
            'attempts' => 2,  // Already tried twice — next attempt marks as failed.
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should fail with "Unknown task type" on the third (final) attempt.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->failed_at, 'Unknown task type should be permanently failed');
        $this->assertStringContains('Unknown task type', $task->error_message ?? '');
    }

    public function test_refer_to_support_sends_plain_text_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser(['fullname' => 'Alice Mod']);
        $otherUser = $this->createTestUser(['fullname' => 'Bob Member']);
        $group = $this->createTestGroup();

        $chatId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $otherUser->id,
            'user2' => $user->id,
            'groupid' => $group->id,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'refer_to_support',
            'data' => json_encode([
                'chatid' => $chatId,
                'userid' => $user->id,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertSent(ReferToSupportMail::class, function (ReferToSupportMail $mail) use ($user, $chatId) {
            $this->assertEquals('Alice Mod', $mail->userName);
            $this->assertEquals($user->id, $mail->userId);
            $this->assertEquals($chatId, $mail->chatId);
            return TRUE;
        });

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_email_verify_sends_verification_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_verify',
            'data' => json_encode([
                'user_id' => $user->id,
                'email' => 'newemail@test.com',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) use ($user) {
            $this->assertEquals($user->id, $mail->userId);
            $this->assertEquals('newemail@test.com', $mail->email);
            $this->assertNotEmpty($mail->confirmUrl);
            return TRUE;
        });

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);

        // Verify a validation key was created in users_emails.
        $emailRow = DB::table('users_emails')
            ->where('email', 'newemail@test.com')
            ->first();
        $this->assertNotNull($emailRow);
        $this->assertNotNull($emailRow->validatekey);
    }

    public function test_email_verify_skips_existing_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $userEmail = $this->createTestUserEmail($user, ['preferred' => 1]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_verify',
            'data' => json_encode([
                'user_id' => $user->id,
                'email' => $userEmail->email,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should not send verification email for existing email.
        Mail::assertNothingSent();

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_email_merge_sends_to_both_users(): void
    {
        Mail::fake();

        $user1 = $this->createTestUser(['fullname' => 'User One']);
        $user1Email = $this->createTestUserEmail($user1, ['preferred' => 1]);
        $user2 = $this->createTestUser(['fullname' => 'User Two']);
        $user2Email = $this->createTestUserEmail($user2, ['preferred' => 1]);

        // Create a merge record.
        $mergeId = DB::table('merges')->insertGetId([
            'user1' => $user1->id,
            'user2' => $user2->id,
            'offeredby' => $user1->id,
            'uid' => 'testuid123',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_merge',
            'data' => json_encode([
                'merge_id' => $mergeId,
                'uid' => 'testuid123',
                'user1' => $user1->id,
                'user2' => $user2->id,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should send to both users.
        Mail::assertSent(MergeOfferMail::class, 2);

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_bcc_specific_sends_extra_email_for_message_reject(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'BCC Test Mod']);
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Create a mod_config with ccrejectto = Specific.
        $configId = DB::table('mod_configs')->insertGetId([
            'name' => 'BCC Test Config',
            'createdby' => $mod->id,
            'ccrejectto' => 'Specific',
            'ccrejectaddr' => 'bcc-archive@example.com',
            'ccfollowupto' => 'Nobody',
            'ccfollowupaddr' => '',
            'ccrejmembto' => 'Nobody',
            'ccrejmembaddr' => '',
            'ccfollmembto' => 'Nobody',
            'ccfollmembaddr' => '',
            'network' => 'Freegle',
        ]);

        // Assign config to the mod's membership.
        DB::table('memberships')
            ->where('userid', $mod->id)
            ->where('groupid', $group->id)
            ->update(['configid' => $configId]);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: BCC Test',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Pending',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Sorry, not suitable',
                'body' => 'Your post does not meet guidelines.',
                'stdmsgid' => 0,
                'action' => 'Reject',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Two emails: one to poster, one BCC.
        Mail::assertSent(ModStdMessageMail::class, 2);

        // Verify BCC copy was sent to the specific address.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) {
            return collect($mail->to)->pluck('address')->contains('bcc-archive@example.com');
        });

        // Verify BCC body has the V1 prefix.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) {
            if (! collect($mail->to)->pluck('address')->contains('bcc-archive@example.com')) {
                return FALSE;
            }
            return str_contains($mail->stdBody, '(This is a BCC of');
        });
    }

    public function test_bcc_me_resolves_to_mod_email_for_member_action(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $memberEmail = $this->createTestUserEmail($member, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'BCC Me Mod']);
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Create a mod_config with ccfollmembto = Me.
        $configId = DB::table('mod_configs')->insertGetId([
            'name' => 'BCC Me Config',
            'createdby' => $mod->id,
            'ccrejectto' => 'Nobody',
            'ccrejectaddr' => '',
            'ccfollowupto' => 'Nobody',
            'ccfollowupaddr' => '',
            'ccrejmembto' => 'Nobody',
            'ccrejmembaddr' => '',
            'ccfollmembto' => 'Me',
            'ccfollmembaddr' => '',
            'network' => 'Freegle',
        ]);

        DB::table('memberships')
            ->where('userid', $mod->id)
            ->where('groupid', $group->id)
            ->update(['configid' => $configId]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Location Required',
                'body' => 'Please update your location.',
                'stdmsgid' => 0,
                'action' => 'Leave Approved Member',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Two emails: one to member, one BCC to the mod.
        Mail::assertSent(ModStdMessageMail::class, 2);

        // The mod's preferred email should be the BCC recipient.
        $modEmail = DB::table('users_emails')
            ->where('userid', $mod->id)
            ->where('preferred', 1)
            ->value('email');

        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($modEmail) {
            return collect($mail->to)->pluck('address')->contains($modEmail);
        });
    }

    public function test_bcc_nobody_sends_no_extra_email(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'No BCC Mod']);
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Config with all CC set to Nobody.
        $configId = DB::table('mod_configs')->insertGetId([
            'name' => 'No BCC Config',
            'createdby' => $mod->id,
            'ccrejectto' => 'Nobody',
            'ccrejectaddr' => '',
            'ccfollowupto' => 'Nobody',
            'ccfollowupaddr' => '',
            'ccrejmembto' => 'Nobody',
            'ccrejmembaddr' => '',
            'ccfollmembto' => 'Nobody',
            'ccfollmembaddr' => '',
            'network' => 'Freegle',
        ]);

        DB::table('memberships')
            ->where('userid', $mod->id)
            ->where('groupid', $group->id)
            ->update(['configid' => $configId]);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: No BCC Test',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Pending',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Not suitable',
                'body' => 'Sorry.',
                'stdmsgid' => 0,
                'action' => 'Reject',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Only one email — to the poster. No BCC.
        Mail::assertSent(ModStdMessageMail::class, 1);
    }

    public function test_bcc_groupname_substitution_in_address(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Subst Mod']);
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Config with $groupname in the BCC address.
        $configId = DB::table('mod_configs')->insertGetId([
            'name' => 'Groupname BCC Config',
            'createdby' => $mod->id,
            'ccrejectto' => 'Specific',
            'ccrejectaddr' => '$groupname-archive@example.com',
            'ccfollowupto' => 'Nobody',
            'ccfollowupaddr' => '',
            'ccrejmembto' => 'Nobody',
            'ccrejmembaddr' => '',
            'ccfollmembto' => 'Nobody',
            'ccfollmembaddr' => '',
            'network' => 'Freegle',
        ]);

        DB::table('memberships')
            ->where('userid', $mod->id)
            ->where('groupid', $group->id)
            ->update(['configid' => $configId]);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Subst Test',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_approved',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Approved!',
                'body' => 'Your message is approved.',
                'stdmsgid' => 0,
                'action' => 'Approve',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // BCC address should have $groupname replaced with the group's nameshort.
        $expectedBcc = $group->nameshort . '-archive@example.com';
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($expectedBcc) {
            return collect($mail->to)->pluck('address')->contains($expectedBcc);
        });
    }

    public function test_bcc_config_fallback_to_other_mod_config(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $modWithConfig = $this->createTestUser(['fullname' => 'Configured Mod']);
        $modWithoutConfig = $this->createTestUser(['fullname' => 'New Mod']);
        $this->createMembership($modWithConfig, $group, ['role' => 'Owner']);
        $this->createMembership($modWithoutConfig, $group, ['role' => 'Moderator']);

        // Only modWithConfig has a config.
        $configId = DB::table('mod_configs')->insertGetId([
            'name' => 'Fallback Config',
            'createdby' => $modWithConfig->id,
            'ccrejectto' => 'Specific',
            'ccrejectaddr' => 'fallback-bcc@example.com',
            'ccfollowupto' => 'Nobody',
            'ccfollowupaddr' => '',
            'ccrejmembto' => 'Nobody',
            'ccrejmembaddr' => '',
            'ccfollmembto' => 'Nobody',
            'ccfollmembaddr' => '',
            'network' => 'Freegle',
        ]);

        DB::table('memberships')
            ->where('userid', $modWithConfig->id)
            ->where('groupid', $group->id)
            ->update(['configid' => $configId]);

        // modWithoutConfig has no configid set — should fall back.
        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Fallback Test',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Pending',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $modWithoutConfig->id,
                'groupid' => $group->id,
                'subject' => 'Rejected',
                'body' => 'Not allowed.',
                'stdmsgid' => 0,
                'action' => 'Reject',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should still send BCC using the fallback config from the other mod.
        Mail::assertSent(ModStdMessageMail::class, 2);
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) {
            return collect($mail->to)->pluck('address')->contains('fallback-bcc@example.com');
        });
    }

    public function test_freebie_alerts_add_calls_api_for_offer(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test sofa',
            'textbody' => 'Free sofa, good condition',
            'type' => 'Offer',
            'lat' => 52.5,
            'lng' => -1.8,
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'arrival' => now(),
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'freebie_alerts_add',
            'data' => json_encode(['msgid' => $msgId]),
            'created_at' => now(),
        ]);

        config(['freegle.freebie_alerts.api_key' => 'test-key-123']);
        config(['freegle.freebie_alerts.api_url' => 'https://api.freebiealerts.app']);

        \Illuminate\Support\Facades\Http::fake([
            'api.freebiealerts.app/freegle/post/create' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), '/freegle/post/create')
                && $request->header('Key')[0] === 'test-key-123';
        });

        $task = DB::table('background_tasks')->where('task_type', 'freebie_alerts_add')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_freebie_alerts_add_skips_wanted_messages(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'WANTED: Test sofa',
            'type' => 'Wanted',
            'lat' => 52.5,
            'lng' => -1.8,
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'freebie_alerts_add',
            'data' => json_encode(['msgid' => $msgId]),
            'created_at' => now(),
        ]);

        config(['freegle.freebie_alerts.api_key' => 'test-key-123']);

        \Illuminate\Support\Facades\Http::fake();

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // No HTTP call should be made for Wanted messages.
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_freebie_alerts_add_skips_when_no_api_key(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test sofa',
            'type' => 'Offer',
            'lat' => 52.5,
            'lng' => -1.8,
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'freebie_alerts_add',
            'data' => json_encode(['msgid' => $msgId]),
            'created_at' => now(),
        ]);

        config(['freegle.freebie_alerts.api_key' => '']);

        \Illuminate\Support\Facades\Http::fake();

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // No HTTP call when API key is empty.
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_freebie_alerts_add_skips_messages_with_outcome(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test sofa',
            'type' => 'Offer',
            'lat' => 52.5,
            'lng' => -1.8,
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgId,
            'outcome' => 'Taken',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'freebie_alerts_add',
            'data' => json_encode(['msgid' => $msgId]),
            'created_at' => now(),
        ]);

        config(['freegle.freebie_alerts.api_key' => 'test-key-123']);

        \Illuminate\Support\Facades\Http::fake();

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_freebie_alerts_remove_calls_api(): void
    {
        $msgId = 99999;

        DB::table('background_tasks')->insert([
            'task_type' => 'freebie_alerts_remove',
            'data' => json_encode(['msgid' => $msgId]),
            'created_at' => now(),
        ]);

        config(['freegle.freebie_alerts.api_key' => 'test-key-123']);
        config(['freegle.freebie_alerts.api_url' => 'https://api.freebiealerts.app']);

        \Illuminate\Support\Facades\Http::fake([
            "api.freebiealerts.app/freegle/post/{$msgId}/delete" => \Illuminate\Support\Facades\Http::response([], 200),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($msgId) {
            return str_contains($request->url(), "/freegle/post/{$msgId}/delete")
                && $request->header('Key')[0] === 'test-key-123';
        });

        $task = DB::table('background_tasks')->where('task_type', 'freebie_alerts_remove')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_freebie_alerts_remove_skips_when_no_api_key(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'freebie_alerts_remove',
            'data' => json_encode(['msgid' => 99999]),
            'created_at' => now(),
        ]);

        config(['freegle.freebie_alerts.api_key' => '']);

        \Illuminate\Support\Facades\Http::fake();

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * Custom assertion for string containment (PHPUnit 10+ compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
