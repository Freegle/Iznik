<?php

namespace Tests\Feature\Authority;

use App\Mail\Authority\AuthorityStatsReminderMail;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SeedsAuthorityStats;
use Tests\TestCase;

class SendCouncilStatsReminderCommandTest extends TestCase
{
    use SeedsAuthorityStats;

    protected function setUp(): void
    {
        parent::setUp();
        // Restrict the run to this test's single seeded authority.
        config([
            'authority_stats.authority_ids' => [$this->authorityId],
            'authority_stats.reminder_recipient' => 'partnerships@ilovefreegle.org',
        ]);
    }

    public function test_sends_reminder_with_generated_spreadsheets_attached(): void
    {
        Mail::fake();
        $this->seedAuthorityScenario();

        $this->artisan('authority:stats-reminder', ['--q' => $this->quarterStart])
            ->assertExitCode(0);

        Mail::assertSent(AuthorityStatsReminderMail::class, function (AuthorityStatsReminderMail $mail) {
            return $mail->hasTo('partnerships@ilovefreegle.org')
                && $mail->quarterLabel === 'Q2 2025'
                && count($mail->attachmentPaths) === 1
                && str_ends_with($mail->attachmentPaths[0], '.xlsx');
        });
    }

    public function test_recipient_can_be_overridden(): void
    {
        Mail::fake();
        $this->seedAuthorityScenario();

        $this->artisan('authority:stats-reminder', [
            '--q' => $this->quarterStart,
            '--to' => 'someone@example.org',
        ])->assertExitCode(0);

        Mail::assertSent(AuthorityStatsReminderMail::class, fn (AuthorityStatsReminderMail $m) => $m->hasTo('someone@example.org'));
    }

    public function test_dry_run_generates_but_does_not_send(): void
    {
        Mail::fake();
        $this->seedAuthorityScenario();

        $this->artisan('authority:stats-reminder', ['--q' => $this->quarterStart, '--dry-run' => true])
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_fails_when_no_authorities_configured(): void
    {
        config(['authority_stats.authority_ids' => []]);

        $this->artisan('authority:stats-reminder')
            ->expectsOutputToContain('No authority IDs configured')
            ->assertExitCode(1);
    }

    public function test_mail_body_renders(): void
    {
        $rendered = (new AuthorityStatsReminderMail('Q2 2026', ['/tmp/a.xlsx', '/tmp/b.xlsx']))->render();

        $this->assertStringContainsString('Q2 2026', $rendered);
        $this->assertStringContainsString('2 spreadsheets are attached', $rendered);
    }
}
