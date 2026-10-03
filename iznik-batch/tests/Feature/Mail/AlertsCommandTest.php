<?php

namespace Tests\Feature\Mail;

use App\Services\AlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // The recipients are every moderator, so the fixture moderators are demoted and each
        // test counts only the moderators it creates. The transaction undoes this.
        DB::table('users')->where('systemrole', '!=', 'User')->update(['systemrole' => 'User']);
    }

    private function createAlert(array $attrs = []): int
    {
        return DB::table('alerts')->insertGetId(array_merge([
            'from' => 'geeks',
            'to' => 'Mods',
            'subject' => 'Test Alert Subject',
            'text' => 'Test alert text body.',
            'html' => '<p>Test alert <strong>HTML</strong> body.</p>',
            'askclick' => 0,
            'tryhard' => 1,
            'groupprogress' => 0,
            'complete' => null,
        ], $attrs));
    }

    private function makeMod(): array
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['systemrole' => 'Moderator']);

        return [$user->id];
    }

    public function test_command_runs_cleanly_with_no_alerts(): void
    {
        $this->artisan('mail:alerts:send')
            ->assertExitCode(0);
    }

    public function test_dry_run_is_accepted(): void
    {
        $this->artisan('mail:alerts:send', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_sends_email_to_mod_for_incomplete_alert(): void
    {
        $this->createAlert();
        $this->makeMod();

        (new AlertService())->processAlerts();

        Mail::assertSent(\App\Mail\Alert\AlertMail::class, 1);
    }

    public function test_marks_alert_complete_after_processing(): void
    {
        $alertId = $this->createAlert();
        $this->makeMod();

        (new AlertService())->processAlerts();

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert->complete);
    }

    public function test_does_not_send_to_already_mailed_email(): void
    {
        $alertId = $this->createAlert();
        [$userId] = $this->makeMod();

        $email = DB::table('users_emails')->where('userid', $userId)->where('preferred', 1)->first();

        DB::table('alerts_tracking')->insert([
            'alertid' => $alertId,
            'userid' => $userId,
            'emailid' => $email->id,
            'type' => 'ModEmail',
        ]);

        (new AlertService())->processAlerts();

        Mail::assertNothingSent();
    }

    public function test_creates_tracking_record_for_each_mod(): void
    {
        $alertId = $this->createAlert();
        $this->makeMod();

        (new AlertService())->processAlerts();

        $trackingCount = DB::table('alerts_tracking')
            ->where('alertid', $alertId)
            ->count();

        $this->assertGreaterThan(0, $trackingCount);
    }

    public function test_skips_already_complete_alerts(): void
    {
        $this->createAlert(['complete' => now()]);
        $this->makeMod();

        (new AlertService())->processAlerts();

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_emails(): void
    {
        $this->createAlert();
        $this->makeMod();

        (new AlertService())->processAlerts(dryRun: true);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_mark_alert_complete(): void
    {
        $alertId = $this->createAlert();
        $this->makeMod();

        (new AlertService())->processAlerts(dryRun: true);

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNull($alert->complete);
    }

    public function test_returns_count_of_emails_sent(): void
    {
        $this->createAlert();
        $this->makeMod();

        $count = (new AlertService())->processAlerts();

        $this->assertSame(1, $count);
    }

    public function test_sends_to_multiple_mods__nationally(): void
    {
        $this->createAlert();
        $this->makeMod();
        $this->makeMod();

        (new AlertService())->processAlerts();

        Mail::assertSent(\App\Mail\Alert\AlertMail::class, 2);
    }

    public function test_does_not_send_to_soft_deleted_user(): void
    {
        $this->createAlert();

        $user = $this->createTestUser(['deleted' => now()]);
        DB::table('users')->where('id', $user->id)->update(['systemrole' => 'Moderator']);

        (new AlertService())->processAlerts();

        Mail::assertNothingSent();
    }
}
