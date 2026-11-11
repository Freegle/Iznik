<?php

namespace Tests\Feature\Mail;

use App\Services\Lockdown\LockdownService;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MailSuppressionService::shouldSkip() and the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.7): shouldSkip() has NO lockdown branch at all.
 * "Suppressed" means a provider bounce; a lockdown hold is a different fact, decided by each
 * mail-generating loop itself via LockdownService::held('email') before it does any work (see
 * ChatNotificationServiceLockdownTest and its siblings for that mechanism). This file only
 * guards against the lockdown branch this class used to have being reintroduced here - ordinary
 * (no lockdown) suppression behaviour is covered by MailSuppressionServiceTest.
 */
class MailSuppressionServiceLockdownTest extends TestCase
{
    private MailSuppressionService $service;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->service = new MailSuppressionService();
    }

    public function test_shouldSkip_is_unaffected_by_an_active_lockdown(): void
    {
        $user = $this->createTestUser();
        $this->lockdown->press(null, 'test lockdown');

        // Email is held, and 'digest_daily' is not on the allowlist - under the old
        // (removed) branch this would have been skipped and counted. It must now flow
        // through to the ordinary provider-suppression check and, since nothing suppresses
        // this address, must not be skipped.
        $this->assertFalse($this->service->shouldSkip($user->email_preferred, $user->id, 'digest_daily'));
    }

    public function test_shouldSkip_writes_no_lockdown_counters_or_acks(): void
    {
        $user = $this->createTestUser();
        $this->lockdown->press(null, 'test lockdown');

        $this->service->shouldSkip($user->email_preferred, $user->id, 'digest_daily');
        $this->service->shouldSkip($user->email_preferred, $user->id, 'password_reset');

        $this->assertSame(0, DB::table('lockdown_counters')->count(), 'counting lockdown effects is now the generating loop\'s job, not shouldSkip\'s');
        $this->assertSame(0, DB::table('lockdown_acks')->where('loop', 'mail-loops')->count(), 'shouldSkip no longer acks mail-loops - the loop itself does, at its own held() check');
    }

    public function test_shouldSkip_still_honours_provider_suppression_while_held(): void
    {
        $user = $this->createTestUser();
        DB::table('mail_suppressions')->insert([
            'scope' => MailSuppressionService::SCOPE_ADDRESS,
            'value' => strtolower($user->email_preferred),
            'reason' => '421 4.7.0 [TSS04] temporarily deferred',
            'provider' => 'Yahoo',
            'deferred_since' => now(),
            'first_seen' => now(),
            'last_seen' => now(),
            'message_count' => 1,
        ]);
        $this->service->flushCache();
        $this->lockdown->press(null, 'test lockdown');

        // The lockdown is a red herring here - this is still a plain provider suppression.
        $this->assertTrue($this->service->shouldSkip($user->email_preferred, $user->id, 'digest_daily'));
    }
}
