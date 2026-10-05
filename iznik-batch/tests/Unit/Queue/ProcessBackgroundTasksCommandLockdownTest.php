<?php

namespace Tests\Unit\Queue;

use App\Mail\Donation\DonateExternalMail;
use App\Mail\Session\ForgotPasswordMail;
use App\Services\Lockdown\LockdownService;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ProcessBackgroundTasksCommand under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.4): "ProcessBackgroundTasksCommand steps over
 * email tasks" not on MailSuppressionService::ALLOWLISTED_EMAIL_TYPES_WHILE_HELD while
 * email is held - left exactly as queued (no attempt spent, no processed_at) for a later
 * iteration once email is lifted. Allowlisted email task types keep flowing. Ordinary
 * (no lockdown) behaviour is covered by ProcessBackgroundTasksCommandTest; this file is
 * only the lockdown branch.
 */
class ProcessBackgroundTasksCommandLockdownTest extends TestCase
{
    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();

        // background_tasks is created by migration 2026_02_09_120000_create_background_tasks_table;
        // it is not created here. A CREATE TABLE run after parent::setUp() is DDL, which MySQL
        // implicitly commits - that silently ends DatabaseTransactions' rollback-able transaction
        // for the rest of the test, so every write this class makes (lockdowns, lockdown_acks,
        // background_tasks) would otherwise leak into the next test uncommitted-turned-committed.
        // That is why test_does_not_ack_with_no_lockdown_ever used to see a lockdown_acks row an
        // earlier test in this class had written.

        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
    }

    protected function tearDown(): void
    {
        // delete(), not truncate(): TRUNCATE is DDL and MySQL implicitly commits on DDL, which
        // would end DatabaseTransactions' transaction before parent::tearDown() rolls it back -
        // the same hazard the setUp() comment above describes, just at the other end of the test.
        DB::table('background_tasks')->delete();
        parent::tearDown();
    }

    public function test_non_allowlisted_email_task_is_stepped_over_while_email_held(): void
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
        $this->lockdown->press(null, 'test lockdown');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertNothingSent();

        // Left exactly as queued - no attempt spent, not processed, not failed.
        $task = DB::table('background_tasks')->first();
        $this->assertNull($task->processed_at);
        $this->assertNull($task->failed_at);
        $this->assertSame(0, $task->attempts);
    }

    public function test_allowlisted_email_task_still_processes_while_email_held(): void
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
        $this->lockdown->press(null, 'test lockdown');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Flush the spool so Mail::fake intercepts the actual SMTP send.
        $this->artisan('mail:spool:process')->assertSuccessful();

        Mail::assertSent(ForgotPasswordMail::class, function (ForgotPasswordMail $mail) {
            return $mail->userId === 11111 && $mail->email === 'forgetful@test.com';
        });

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_stepped_over_task_processes_once_email_is_lifted(): void
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
        $this->lockdown->press(null, 'test lockdown');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $held = DB::table('background_tasks')->first();
        $this->assertNull($held->processed_at, 'precondition: stepped over while held');

        $this->lockdown->setSurfaces(['email' => false], null);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->artisan('mail:spool:process')->assertSuccessful();

        Mail::assertSent(DonateExternalMail::class);

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_non_email_task_is_not_affected_by_email_lockdown(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_chat_message',
            'data' => json_encode(['message_id' => 12345]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyChatMessage')->once()->with(12345)->andReturn(1);

        $this->lockdown->press(null, 'test lockdown');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at, 'email being held must not step over non-email tasks');
    }

    public function test_ack_is_written_for_each_task_passing_through_the_loop(): void
    {
        Mail::fake();
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_chat_message',
            'data' => json_encode(['message_id' => 12345]),
            'created_at' => now(),
        ]);
        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyChatMessage')->once()->with(12345)->andReturn(1);

        $this->lockdown->press(null, 'test lockdown');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $ack = DB::table('lockdown_acks')->where('loop', 'background-tasks')->first();
        $this->assertNotNull($ack, 'background-tasks must ack so the presser sees this loop take effect');
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_does_not_ack_with_no_lockdown_ever(): void
    {
        Mail::fake();
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_chat_message',
            'data' => json_encode(['message_id' => 12345]),
            'created_at' => now(),
        ]);
        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyChatMessage')->once()->with(12345)->andReturn(1);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertNull(DB::table('lockdown_acks')->where('loop', 'background-tasks')->first());
    }
}
