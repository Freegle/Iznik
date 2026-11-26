<?php

namespace Tests\Feature\Servers;

use App\Mail\Servers\ServerSizingReportMail;
use App\Models\ServerSample;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * servers:rightsize-report end to end against a made-up Katapult account: Alpha is a big idle
 * server with three weeks of readings, Beta is a small one nobody has sampled, Gamma is stopped.
 */
class RightsizeReportCommandTest extends TestCase
{
    use ServerSizingTestData;

    private const REPLY = "CHANGES\n- Move Alpha down to C-12.\nWATCH\n- Nothing this week.\nCAVEATS\n- Beta is not measured.\n";

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        ServerSample::whereIn('host', ['alpha', 'beta', 'gamma'])->delete();
        $this->fakeKatapult();
        config([
            'freegle.servers.report_to' => 'sizing@example.org',
            'freegle.servers.price_discount' => 0.5,
            'freegle.servers.oauth_token' => 'tok-123',
            'freegle.servers.min_history_days' => 14,
        ]);
        Process::fake(['*' => Process::result(output: json_encode(['type' => 'result', 'result' => self::REPLY]))]);
    }

    public function test_the_report_is_mailed_with_the_verdicts_and_the_review(): void
    {
        $this->seedSamples('alpha', 21, 12, 1.0, 36000, 6144);

        $this->artisan('servers:rightsize-report')
            ->expectsOutputToContain('Sizing report sent to sizing@example.org.')
            ->assertExitCode(0);

        Mail::assertSent(ServerSizingReportMail::class, function (ServerSizingReportMail $mail) {
            $cards = collect($mail->cards())->keyBy('name');

            return $mail->hasTo('sizing@example.org')
                && $mail->envelope()->subject === 'Server sizing: 1 size change suggested'
                && $mail->review['changes'] === ['Move Alpha down to C-12.']
                // 136 now, 44 + 150 GB extra disk at 0.2 = 74 on C-12, half price: saves 31.
                && $cards['Alpha']['verdict'] === 'Move down to C-12: saves £31.00 a month'
                && $cards['Alpha']['tone'] === 'act'
                && $cards['Alpha']['cost'] === '£68.00 a month'
                && $cards['Beta']['verdict'] === 'Not measured'
                && $cards['Gamma']['verdict'] === 'Stopped'
                && $mail->analysis['fleet']['possible_saving_paid'] === 31.0;
        });
        // The AI is handed the worded verdict, not raw numbers to work from.
        Process::assertRan(fn ($process) => str_contains(
            ((array) $process->command)[array_search('-p', (array) $process->command, true) + 1],
            'Move down to C-12: saves £31.00 a month'
        ));
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_with_too_little_history_it_reports_without_recommending(): void
    {
        $this->seedSamples('alpha', 2, 12, 1.0, 36000, 6144);

        $this->artisan('servers:rightsize-report')->assertExitCode(0);

        Mail::assertSent(ServerSizingReportMail::class, function (ServerSizingReportMail $mail) {
            $alpha = collect($mail->cards())->firstWhere('name', 'Alpha');

            return $mail->envelope()->subject === 'Server sizing: no size changes suggested'
                && $alpha['verdict'] === 'Too early to say. So far: could move down to C-12, saving £31.00 a month'
                && $alpha['tone'] === 'info';
        });
    }

    public function test_a_dry_run_prints_the_verdicts_and_sends_nothing(): void
    {
        $this->seedSamples('alpha', 21, 12, 1.0, 36000, 6144);
        config(['freegle.servers.report_to' => '']);

        $this->artisan('servers:rightsize-report', ['--dry-run' => true])
            ->expectsOutputToContain('Move down to C-12')
            ->expectsOutputToContain('Dry run: nothing sent.')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_the_to_option_overrides_the_configured_address_and_no_review_skips_the_ai(): void
    {
        $this->artisan('servers:rightsize-report', ['--to' => 'someone@example.org', '--no-review' => true])
            ->expectsOutputToContain('Sizing report sent to someone@example.org without the AI review.')
            ->assertExitCode(0);

        Mail::assertSent(ServerSizingReportMail::class, fn (ServerSizingReportMail $mail) => $mail->hasTo('someone@example.org') && $mail->review === null);
        Process::assertNothingRan();
    }

    public function test_without_a_katapult_token_or_a_recipient_it_does_nothing(): void
    {
        config(['freegle.servers.report_to' => '']);
        $this->artisan('servers:rightsize-report')
            ->expectsOutputToContain('SERVERS_REPORT_TO is not set')
            ->assertExitCode(0);

        config(['freegle.servers.report_to' => 'sizing@example.org', 'freegle.servers.katapult_token' => '']);
        $this->artisan('servers:rightsize-report')
            ->expectsOutputToContain('KATAPULT_API_TOKEN is not set')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
