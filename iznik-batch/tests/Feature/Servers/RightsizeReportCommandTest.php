<?php

namespace Tests\Feature\Servers;

use App\Mail\Servers\ServerSizingReportMail;
use App\Models\ServerJobAlert;
use App\Models\ServerSample;
use App\Services\Servers\ServerSizingReport;
use Illuminate\Support\Carbon;
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
                // 136 now and 44 on C-12, the disk the same on both, half price: saves 46.
                && $cards['Alpha']['verdict'] === 'Move down to C-12: saves £46.00 a month'
                && $cards['Alpha']['tone'] === 'act'
                && $cards['Alpha']['cost'] === '£98.00 a month'
                && $cards['Beta']['verdict'] === 'Not measured'
                && $cards['Gamma']['verdict'] === 'Stopped'
                && $mail->analysis['fleet']['possible_saving_paid'] === 46.0;
        });
        // The AI is handed the worded verdict, not raw numbers to work from.
        Process::assertRan(fn ($process) => str_contains(
            ((array) $process->command)[array_search('-p', (array) $process->command, true) + 1],
            'Move down to C-12: saves £46.00 a month'
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
                && $alpha['verdict'] === 'Too early to say. So far: could move down to C-12, saving £46.00 a month'
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

    public function test_several_addresses_each_get_a_copy_and_the_review_is_asked_for_once(): void
    {
        $this->seedSamples('alpha', 21, 12, 1.0, 36000, 6144);
        config(['freegle.servers.report_to' => 'one@example.org, two@example.org,,one@example.org']);

        $this->artisan('servers:rightsize-report')
            ->expectsOutputToContain('Sizing report sent to one@example.org, two@example.org.')
            ->assertExitCode(0);

        Mail::assertSent(ServerSizingReportMail::class, 2);
        Mail::assertSent(ServerSizingReportMail::class, fn (ServerSizingReportMail $mail) => $mail->hasTo('one@example.org') && ! $mail->hasTo('two@example.org'));
        Mail::assertSent(ServerSizingReportMail::class, fn (ServerSizingReportMail $mail) => $mail->hasTo('two@example.org') && ! $mail->hasTo('one@example.org'));
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_notes_about_servers_and_the_week_s_late_jobs_reach_the_verdicts_and_the_invoice(): void
    {
        $this->seedSamples('alpha', 3, 12, 20.0, 36000, 6144);
        $slug = 'digest backlog '.uniqid();
        ServerJobAlert::where('sampled_at', '>=', Carbon::now()->subDays(8))->delete();
        foreach (range(1, 6) as $look) {
            ServerJobAlert::create(['slug' => $slug, 'sampled_at' => Carbon::now()->subHours($look)]);
        }
        ServerJobAlert::create(['slug' => $slug, 'sampled_at' => Carbon::now()->subDays(9)]);
        config([
            'freegle.servers.host_notes' => ' alpha = bulk | Background work, meant to run flat out. ; gamma=keep|Kept so its work can be resumed.',
            'freegle.servers.other_monthly_cost' => 20,
            'freegle.servers.vat_rate' => 0.2,
        ]);

        $this->artisan('servers:rightsize-report', ['--no-review' => true])->assertExitCode(0);

        Mail::assertSent(ServerSizingReportMail::class, function (ServerSizingReportMail $mail) use ($slug) {
            $cards = collect($mail->cards())->keyBy('name');
            $fleet = $mail->analysis['fleet'];

            // Six looks in the week are an hour and a half; the one nine days ago is not counted.
            return $cards['Alpha']['verdict'] === 'Not keeping up, and there is no bigger package of this kind'
                && $cards['Alpha']['reasons'] === ['Background work, meant to run flat out.', "Late-job alerts in the last week: {$slug} (about 1.5 hours)."]
                && $cards['Gamma']['verdict'] === 'Kept on purpose'
                && $cards['Gamma']['reasons'] === ['Kept so its work can be resumed.', 'Stopped, but still charged for its package and its disk.']
                && $fleet['other_list'] === 20.0
                && $fleet['invoice_before_vat'] === round(($fleet['monthly_list'] + 20) * 0.5, 2)
                && $fleet['invoice_expected'] === round(($fleet['monthly_list'] + 20) * 0.5 * 1.2, 2)
                && $mail->envelope()->subject === 'Server sizing: 1 size change suggested';
        });
    }

    public function test_server_notes_are_read_from_the_setting_and_a_slip_is_left_out(): void
    {
        $this->assertSame([
            'db-9' => ['role' => 'bulk', 'note' => 'Background work, flat out is fine'],
            'spare' => ['role' => 'keep', 'note' => ''],
        ], ServerSizingReport::parseHostNotes('DB-9=bulk|Background work, flat out is fine;spare = keep;oops=fast|not a role;;nonsense'));
        $this->assertSame([], ServerSizingReport::parseHostNotes(''));
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
