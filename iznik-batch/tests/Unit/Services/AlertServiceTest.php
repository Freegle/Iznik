<?php

namespace Tests\Unit\Services;

use App\Services\AlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertServiceTest extends TestCase
{
    private AlertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AlertService;

        // Intercept all outgoing mail so Mail::assertSent*/assertNothingSent work
        // and no real SMTP connection is attempted. Without this, those assertions
        // throw "method does not exist" because the Mail facade is the real manager.
        Mail::fake();

        // processAlerts() acts on ALL incomplete alerts in the DB. The alerts /
        // alerts_tracking tables are not rolled back by DatabaseTransactions in
        // this suite, so rows from other tests would inflate the per-test counts.
        // Start each test from a known-empty state.
        DB::table('alerts_tracking')->delete();
        DB::table('alerts')->delete();

        // Every moderator is a recipient, so the fixture moderators are demoted and each test counts
        // only the moderators it creates. The transaction undoes this.
        DB::table('users')->where('systemrole', '!=', 'User')->update(['systemrole' => 'User']);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function insertAlert(array $attrs = []): int
    {
        return DB::table('alerts')->insertGetId(array_merge([
            'from' => 'geeks',
            'to' => 'Mods',
            'subject' => 'Test Alert Subject',
            'text' => 'Test alert body text.',
            'html' => '<p>Test alert body.</p>',
            'groupprogress' => 0,
            'complete' => null,
            'tryhard' => 1,
            'askclick' => false,
        ], $attrs));
    }

    private function invokeResolveFrom(string $role): array
    {
        $method = new \ReflectionMethod(AlertService::class, 'resolveFrom');
        $method->setAccessible(true);
        return $method->invoke($this->service, $role);
    }

    // ===================================================================
    // processAlerts — no alerts
    // ===================================================================

    public function test_processAlerts_returns_zero_when_no_incomplete_alerts(): void
    {
        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
    }

    public function test_processAlerts_ignores_completed_alerts(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        // Insert a completed alert — should be ignored.
        $this->insertAlert(['complete' => now()]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
    }

    // ===================================================================
    // processAlerts — dry-run
    // ===================================================================

    public function test_processAlerts_dryRun_returns_zero_and_sends_no_mail(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert();

        $result = $this->service->processAlerts(dryRun: true);

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_dryRun_does_not_mark_alert_complete(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $alertId = $this->insertAlert();

        $this->service->processAlerts(dryRun: true);

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNull($alert->complete, 'alert must not be marked complete in dry-run');
    }

    public function test_processAlerts_dryRun_does_not_insert_tracking_records(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $alertId = $this->insertAlert();

        $this->service->processAlerts(dryRun: true);

        $tracking = DB::table('alerts_tracking')->where('alertid', $alertId)->count();
        $this->assertSame(0, $tracking, 'No tracking rows should be inserted in dry-run');
    }

    // ===================================================================
    // processAlerts — real run
    // ===================================================================

    public function test_processAlerts_sends_email_to_moderator(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert([]);

        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
        Mail::assertSentCount(1);
    }

    public function test_processAlerts_sends_email_to_owner(): void
    {
        $owner = $this->createTestUser();
        DB::table('users')->where('id', $owner->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert([]);

        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
    }

    public function test_processAlerts_does_not_send_to_member_role(): void
    {
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);

        $this->insertAlert([]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_marks_alert_complete_when_under_batch_limit(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $alertId = $this->insertAlert([]);

        $this->service->processAlerts();

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert->complete, 'alert should be marked complete after processing');
    }

    public function test_processAlerts_inserts_tracking_record(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $alertId = $this->insertAlert([]);

        $this->service->processAlerts();

        $tracking = DB::table('alerts_tracking')
            ->where('alertid', $alertId)
            ->where('userid', $mod->id)
            ->first();

        $this->assertNotNull($tracking);
        $this->assertSame('ModEmail', $tracking->type);
    }

    // ===================================================================
    // processAlerts — multiple mods
    // ===================================================================

    public function test_processAlerts_sends_to_multiple_mods(): void
    {
        $mod1 = $this->createTestUser();
        $mod2 = $this->createTestUser();
        DB::table('users')->where('id', $mod1->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);
        DB::table('users')->where('id', $mod2->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert([]);

        $result = $this->service->processAlerts();

        $this->assertSame(2, $result);
    }

    public function test_processAlerts_returns_total_across_multiple_alerts(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert([]);
        $this->insertAlert(['subject' => 'Second Alert']);

        $result = $this->service->processAlerts();

        // Two alerts × one mod = 2 emails
        $this->assertSame(2, $result);
    }

    // ===================================================================
    // processAlerts — skip conditions
    // ===================================================================

    public function test_processAlerts_skips_deleted_users(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        // Mark user as deleted.
        DB::table('users')->where('id', $mod->id)->update(['deleted' => now()]);

        $this->insertAlert([]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_skips_bounced_email(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        // Mark the user's email as bounced.
        DB::table('users_emails')->where('userid', $mod->id)->update(['bounced' => now()]);

        $this->insertAlert([]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    // ===================================================================
    // processAlerts — html vs text body
    // ===================================================================

    public function test_processAlerts_uses_html_field_when_present(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert([
            'html' => '<b>HTML body</b>',
            'text' => 'Plain body',
        ]);

        $this->service->processAlerts();

        // No exception means the mail was sent with whichever body.
        Mail::assertSentCount(1);
    }

    public function test_processAlerts_falls_back_to_nl2br_text_when_html_empty(): void
    {
        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $this->insertAlert([
            'html' => '',
            'text' => "Line one\nLine two",
        ]);

        $this->service->processAlerts();

        Mail::assertSentCount(1);
    }

    // ===================================================================
    // resolveFrom — known roles with config lookup
    // ===================================================================

    public function test_resolveFrom_support_returns_config_addr(): void
    {
        config(['freegle.mail.support_addr' => 'support@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('support');

        $this->assertSame('support@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Support', $name);
    }

    public function test_resolveFrom_info_returns_config_addr(): void
    {
        config(['freegle.mail.info_addr' => 'info@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('info');

        $this->assertSame('info@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Info', $name);
    }

    public function test_resolveFrom_geeks_returns_config_addr(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('geeks');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Geeks', $name);
    }

    public function test_resolveFrom_mentors_returns_config_addr(): void
    {
        config(['freegle.mail.mentors_addr' => 'mentors@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('mentors');

        $this->assertSame('mentors@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Mentors', $name);
    }

    // ===================================================================
    // resolveFrom — known roles with hardcoded address
    // ===================================================================

    #[DataProvider('hardcodedRoleProvider')]
    public function test_resolveFrom_hardcoded_roles(string $role, string $expectedAddr, string $expectedName): void
    {
        [$addr, $name] = $this->invokeResolveFrom($role);

        $this->assertSame($expectedAddr, $addr);
        $this->assertSame($expectedName, $name);
    }

    public static function hardcodedRoleProvider(): array
    {
        return [
            'board'       => ['board',       'board@ilovefreegle.org',       'Freegle Board'],
            'chair'       => ['chair',       'chair@ilovefreegle.org',       'Freegle Chair'],
            'newgroups'   => ['newgroups',   'newgroups@ilovefreegle.org',   'Freegle New Groups'],
            'ro'          => ['ro',          'ro@ilovefreegle.org',          'Freegle Returning Officer'],
            'volunteers'  => ['volunteers',  'volunteers@ilovefreegle.org',  'Freegle Volunteers'],
            'centralmods' => ['centralmods', 'centralmods@ilovefreegle.org', 'Freegle Volunteer Support'],
            'councils'    => ['councils',    'councils@ilovefreegle.org',    'Freegle Partnerships'],
        ];
    }

    // ===================================================================
    // resolveFrom — unknown role fallback
    // ===================================================================

    public function test_resolveFrom_unknown_role_falls_back_to_geeks_addr(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('unknownrole');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
        $this->assertSame('Freegle', $name);
    }

    public function test_resolveFrom_empty_role_falls_back_to_geeks_addr(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
        $this->assertSame('Freegle', $name);
    }

    // ===================================================================
    // resolveFrom — config fallback within known roles
    // ===================================================================

    public function test_resolveFrom_support_falls_back_to_geeks_when_config_absent(): void
    {
        // If FREEGLE_SUPPORT_ADDR is not set, the config falls back to FREEGLE_GEEKS_ADDR.
        config([
            'freegle.mail.support_addr' => null,
            'freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org',
        ]);

        [$addr] = $this->invokeResolveFrom('support');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
    }
}
