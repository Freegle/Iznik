<?php

namespace Tests\Feature\Mail;

use App\Mail\Engage\EngageMail;
use App\Models\Group;
use App\Services\EngageEmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EngageEmailsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createFreegleGroup(): Group
    {
        return Group::create([
            'nameshort' => 'TestEngage_' . uniqid(),
            'type'      => Group::TYPE_FREEGLE,
            'publish'   => 1,
            'onmap'     => 1,
            'onhere'    => 1,
            'lat'       => 51.5074,
            'lng'       => -0.1278,
        ]);
    }

    private function createUserWithGroupMembership(array $userAttributes = []): object
    {
        $user = $this->createTestUser($userAttributes);
        $group = $this->createFreegleGroup();
        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'role' => 'Member',
            'added' => now(),
        ]);
        return $user;
    }

    private function createEngageMail(string $engagement, string $template = 'missing'): object
    {
        $id = DB::table('engage_mails')->insertGetId([
            'engagement' => $engagement,
            'template' => $template,
            'subject' => "Test {$engagement} subject",
            'text' => "Test {$engagement} text",
            'shown' => 0,
            'action' => 0,
            'rate' => 0,
        ]);
        return DB::table('engage_mails')->where('id', $id)->first();
    }

    private function setLastAccess(int $userId, string $date): void
    {
        DB::table('users')->where('id', $userId)->update(['lastaccess' => $date]);
    }

    private function atRiskDate(): string
    {
        return now()->subSeconds(EngageEmailService::USER_INACTIVE_SECONDS)->addDays(EngageEmailService::AT_RISK_WARNING_DAYS)->toDateString();
    }

    // ── Basic smoke tests ────────────────────────────────────────────────────

    public function test_command_runs_with_no_data(): void
    {
        $this->artisan('mail:engage')
            ->assertExitCode(0);
    }

    public function test_dry_run_shows_prefix(): void
    {
        $this->artisan('mail:engage', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }

    // ── At-risk emails ───────────────────────────────────────────────────────

    public function test_sends_at_risk_email(): void
    {
        $user = $this->createUserWithGroupMembership(['relevantallowed' => 0]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_AT_RISK, 'inactive');
        $this->setLastAccess($user->id, $this->atRiskDate());

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(1, $result['at_risk_sent']);
        Mail::assertSent(EngageMail::class);
    }

    public function test_at_risk_sends_even_when_relevantallowed_false(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update(['relevantallowed' => 0]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_AT_RISK, 'inactive');
        $this->setLastAccess($user->id, $this->atRiskDate());

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(1, $result['at_risk_sent']);
    }

    public function test_does_not_send_at_risk_for_wrong_date(): void
    {
        $user = $this->createUserWithGroupMembership();
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_AT_RISK, 'inactive');
        $this->setLastAccess($user->id, now()->subDays(10)->toDateString());

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['at_risk_sent']);
        Mail::assertNothingSent();
    }

    // ── Inactive emails ──────────────────────────────────────────────────────

    public function test_sends_inactive_email(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(1, $result['inactive_sent']);
        Mail::assertSent(EngageMail::class);
    }

    public function test_skips_inactive_user_with_relevantallowed_false(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 0,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['inactive_sent']);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_no_engage_mail_variants(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        // No engage_mails rows inserted

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['inactive_sent']);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_to_user_without_membership(): void
    {
        $user = $this->createTestUser(['relevantallowed' => 1]);
        DB::table('users')->where('id', $user->id)->update(['engagement' => EngageEmailService::ENGAGEMENT_INACTIVE]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['inactive_sent']);
        Mail::assertNothingSent();
    }

    public function test_respects_weekly_resend_interval(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $engageMail = $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        // Record a recent attempt (3 days ago)
        DB::table('engage')->insert([
            'userid' => $user->id,
            'mailid' => $engageMail->id,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
            'timestamp' => now()->subDays(3),
        ]);

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['inactive_sent']);
        Mail::assertNothingSent();
    }

    public function test_sends_after_resend_interval_has_passed(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $engageMail = $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        // Record an old attempt (8 days ago)
        DB::table('engage')->insert([
            'userid' => $user->id,
            'mailid' => $engageMail->id,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
            'timestamp' => now()->subDays(8),
        ]);

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(1, $result['inactive_sent']);
    }

    // ── Dry run ─────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_send_emails(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        (new EngageEmailService())->processEngageEmails(true);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_record_attempts(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        (new EngageEmailService())->processEngageEmails(true);

        $this->assertDatabaseMissing('engage', ['userid' => $user->id]);
    }

    // ── Engage table tracking ────────────────────────────────────────────────

    public function test_records_engage_attempt_after_send(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        (new EngageEmailService())->processEngageEmails(false);

        $this->assertDatabaseHas('engage', [
            'userid' => $user->id,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
    }

    public function test_increments_engage_mail_shown_count(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $engageMail = $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        (new EngageEmailService())->processEngageEmails(false);

        $updated = DB::table('engage_mails')->where('id', $engageMail->id)->value('shown');
        $this->assertSame(1, (int) $updated);
    }
}
