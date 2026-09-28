<?php

namespace Tests\Feature\Mail;

use App\Models\BatchEmailProgress;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SendPendingWelcomeMailsCommand under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.4): welcome mail is not on the lockdown
 * allowlist, so the whole run is skipped while email is held rather than generating and
 * then holding each mail in spool. Ordinary (no lockdown) behaviour has no dedicated test
 * file of its own yet; this file is only the lockdown branch.
 */
class SendPendingWelcomeMailsCommandLockdownTest extends TestCase
{
    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('batch_email_progress')->where('job_type', 'welcome')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
    }

    public function test_skips_the_whole_run_while_email_is_held(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $this->createTestUserEmail($user, ['preferred' => 1]);

        $this->lockdown->press(null, 'test lockdown');

        $this->artisan('mail:welcome:send')->assertSuccessful();

        Mail::assertNothingSent();

        // Held rather than caught up mid-run: progress is left exactly as it was, so the
        // user is still picked up on the first run after the lockdown lifts.
        $this->assertNull(BatchEmailProgress::forJob('welcome')->last_processed_id);
    }

    public function test_sends_normally_once_email_is_lifted(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $this->createTestUserEmail($user, ['preferred' => 1]);

        // Initialise progress to before this user, as the command itself would on a real
        // first run, so the held run below has someone to (not) pick up.
        $progress = BatchEmailProgress::forJob('welcome');
        $progress->last_processed_id = $user->id - 1;
        $progress->save();

        $this->lockdown->press(null, 'test lockdown');
        $this->artisan('mail:welcome:send')->assertSuccessful();
        Mail::assertNothingSent();

        $this->lockdown->setSurfaces(['email' => false], null);
        $this->artisan('mail:welcome:send')->assertSuccessful();

        Mail::assertSent(\App\Mail\Welcome\WelcomeMail::class);
    }
}
