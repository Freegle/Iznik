<?php

namespace Tests\Unit\Mail;

use App\Mail\Servers\ServerSizingReportMail;
use Tests\TestCase;

/**
 * The wording of the server sizing report: the subject, each server's card, and the two views.
 * The views are rendered from Blade here, before MJML, so the test needs no MJML service.
 */
class ServerSizingReportMailTest extends TestCase
{
    private function server(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Alpha',
            'hostname' => 'alpha',
            'state' => 'started',
            'package' => ['name' => 'C-24', 'cpus' => 8, 'memory_gb' => 24, 'disk_gb' => 225],
            'package_name' => 'C-24',
            'disk_total_gb' => 300,
            'disk_cost' => ['list' => 60.0, 'paid' => 30.0],
            'ipv4' => 1,
            'cost' => ['list' => 101.25, 'paid' => 50.63],
            'measured' => true,
            'history_days' => 21,
            'week' => [
                'samples' => 672, 'cpus' => 8, 'mem_total_gb' => 23.4, 'load_avg' => 6.1, 'load_p95' => 8.43,
                'load_per_cpu_p95' => 1.05, 'cpu_busy_avg' => 0.41, 'cpu_busy_p95' => 0.667,
                'mem_used_pct_p95' => 0.81, 'mem_used_gb_p95' => 19.0, 'swap_used_mb_max' => 4096,
            ],
            'trend' => [
                ['week_ending' => '23 Nov', 'load_per_cpu_p95' => 0.98, 'cpu_busy_p95' => 0.6, 'mem_used_pct_p95' => 0.8, 'disk_used_gb' => 208.0],
                ['week_ending' => '30 Nov', 'load_per_cpu_p95' => 1.05, 'cpu_busy_p95' => 0.667, 'mem_used_pct_p95' => 0.81, 'disk_used_gb' => 210.5],
            ],
            'disks' => [
                ['mount' => '/', 'size_gb' => 296.0, 'used_gb' => 210.5, 'used_pct' => 0.711, 'growth_gb_per_week' => 2.5, 'weeks_to_90' => 22.4, 'flag' => false],
            ],
            'needs' => ['cpus' => 13, 'memory_gb' => 23.8],
            'steps' => [
                'down' => ['name' => 'C-18', 'cpus' => 6, 'memory_gb' => 18, 'disk_gb' => 200, 'cost_paid' => 41.25],
                'up' => ['name' => 'C-36', 'cpus' => 12, 'memory_gb' => 36, 'disk_gb' => 300, 'cost_paid' => 67.5],
            ],
            'verdict' => 'step_up',
            'indication' => null,
            'candidate' => ['name' => 'C-36', 'cpus' => 12, 'memory_gb' => 36, 'disk_gb' => 300, 'cost_list' => 135.0, 'cost_paid' => 67.5, 'saving_paid' => -16.87],
            'reasons' => ['Load is above the number of processors for part of the week (1.05 per processor).'],
        ], $overrides);
    }

    private function mail(array $servers, ?array $review = null, array $analysis = []): ServerSizingReportMail
    {
        return new ServerSizingReportMail(array_merge([
            'generated_at' => '2026-11-30 08:00:00',
            'discount' => 0.5,
            'history_days' => 21,
            'min_history_days' => 14,
            'fleet' => ['monthly_list' => 720.0, 'monthly_paid' => 360.0, 'possible_saving_paid' => 0.0],
            'servers' => $servers,
            'unattached_disks' => [],
        ], $analysis), $review, 'sizing@example.org');
    }

    public function test_a_card_words_every_figure(): void
    {
        $card = $this->mail([$this->server()])->cards()[0];

        $this->assertSame('Alpha', $card['name']);
        $this->assertSame('C-24: 8 processors, 24 GB memory', $card['package']);
        $this->assertSame('£50.63 a month', $card['cost']);
        $this->assertSame('Move up to C-36: costs £16.87 more a month', $card['verdict']);
        $this->assertSame('act', $card['tone']);
        $this->assertSame([
            ['Load, busy end of the week', '8.43 on 8 processors (1.05 each)'],
            ['Processor time in use', '67% at the busy end, 41% on average'],
            ['Memory in use', '19 of 23.4 GB (81%), swap up to 4 GB'],
            ['Disk /', '210.5 of 296 GB (71%), +2.5 GB a week, 90% full in about 22.4 weeks'],
            ['Disk rented', '300 GB, which is £30.00 of the monthly cost'],
            ['Steps available', 'Next size down: C-18 (6 processors, 18 GB) £41.25; Next size up: C-36 (12 processors, 36 GB) £67.50'],
        ], $card['lines']);
        $this->assertSame([
            '23 Nov: load 0.98 per processor, memory 80%, disk 208 GB',
            '30 Nov: load 1.05 per processor, memory 81%, disk 210.5 GB',
        ], $card['trend']);
    }

    public function test_the_ai_review_is_given_the_same_worded_figures_the_reader_sees(): void
    {
        $mail = $this->mail([$this->server()], null, [
            'fleet' => ['monthly_list' => 720.0, 'monthly_paid' => 360.0, 'possible_saving_paid' => 0.0, 'possible_cost_paid' => 16.87],
            'unattached_disks' => [['name' => 'old-cache', 'size_gb' => 200, 'state' => 'built', 'machine_id' => null]],
            'unmatched_hosts' => ['stray'],
        ]);
        $facts = ServerSizingReportMail::factsFor($mail->analysis);

        $this->assertSame('£360.00', $facts['we_pay_a_month']);
        $this->assertSame('£0.00', $facts['moves_down_would_save_a_month']);
        $this->assertSame('£16.87', $facts['moves_up_would_cost_a_month_more']);
        $this->assertSame(21, $facts['days_of_readings']);
        $this->assertSame(14, $facts['days_needed_before_a_move_down']);
        $this->assertSame(['old-cache, 200 GB, not attached to any server'], $facts['disks_to_look_at']);
        $this->assertSame(['stray'], $facts['hosts_sampled_but_not_on_katapult']);
        // Each server is its card, less the colour hint that means nothing to a reader of text.
        $card = $mail->cards()[0];
        unset($card['tone']);
        $this->assertSame([$card], $facts['servers']);
        $this->assertSame('Move up to C-36: costs £16.87 more a month', $facts['servers'][0]['verdict']);
    }

    public function test_each_verdict_has_its_own_words(): void
    {
        $down = ['name' => 'C-12', 'saving_paid' => 22.5];
        $cases = [
            'Keep as it is' => ['verdict' => 'keep', 'candidate' => null],
            'Move down to C-12: saves £22.50 a month' => ['verdict' => 'step_down', 'candidate' => $down],
            'Too small, and there is no bigger package of this kind' => ['verdict' => 'step_up', 'candidate' => null],
            'Move up to C-36: costs no more' => ['verdict' => 'step_up', 'candidate' => ['name' => 'C-36', 'saving_paid' => 3.0]],
            'Too early to say. So far: the size looks right' => ['verdict' => 'wait', 'indication' => 'keep', 'candidate' => null],
            'Too early to say. So far: could move down to C-12, saving £22.50 a month' => ['verdict' => 'wait', 'indication' => 'step_down', 'candidate' => $down],
            'Too early to say. So far: looks too small; C-36 costs £16.87 more a month' => ['verdict' => 'wait', 'indication' => 'step_up'],
            'Too early to say. So far: looks too small' => ['verdict' => 'wait', 'indication' => 'step_up', 'candidate' => null],
            'Stopped' => ['verdict' => 'stopped', 'candidate' => null],
            'Not measured' => ['verdict' => 'unmeasured', 'candidate' => null],
        ];

        foreach ($cases as $expected => $overrides) {
            $server = array_merge($this->server(), $overrides);
            $this->assertSame($expected, $this->mail([$server])->cards()[0]['verdict']);
        }
    }

    public function test_an_unmeasured_server_has_no_reading_lines_and_a_filling_disk_is_named(): void
    {
        $bare = $this->mail([array_merge($this->server(), [
            'week' => null, 'disks' => [], 'trend' => [], 'disk_total_gb' => 0, 'package' => null,
            'steps' => ['down' => null, 'up' => null], 'verdict' => 'unmeasured', 'candidate' => null,
        ])])->cards()[0];
        $this->assertSame([], $bare['lines']);
        $this->assertSame('C-24', $bare['package']);

        $filling = $this->mail([array_merge($this->server(), ['disks' => [
            ['mount' => '/', 'size_gb' => 512.0, 'used_gb' => 470.0, 'used_pct' => 0.918, 'growth_gb_per_week' => 3.0, 'weeks_to_90' => 0.0, 'flag' => true],
            ['mount' => '/data', 'size_gb' => 75.0, 'used_gb' => 9.5, 'used_pct' => 0.127, 'growth_gb_per_week' => null, 'weeks_to_90' => null, 'flag' => false],
            ['mount' => '/logs', 'size_gb' => 50.0, 'used_gb' => 20.0, 'used_pct' => 0.4, 'growth_gb_per_week' => -1.25, 'weeks_to_90' => null, 'flag' => false],
        ]])])->cards()[0]['lines'];

        $this->assertContains(['Disk filling: /', '470 of 512 GB (92%), +3 GB a week, already past 90%'], $filling);
        $this->assertContains(['Disk /data', '9.5 of 75 GB (13%), growth not known yet'], $filling);
        $this->assertContains(['Disk /logs', '20 of 50 GB (40%), -1.25 GB a week'], $filling);
    }

    public function test_the_subject_counts_changes_and_filling_disks(): void
    {
        $keep = array_merge($this->server(), ['verdict' => 'keep', 'candidate' => null]);
        $filling = $keep;
        $filling['disks'][0]['flag'] = true;

        $this->assertSame('Server sizing: no size changes suggested', $this->mail([$keep])->envelope()->subject);
        $this->assertSame('Server sizing: 1 size change suggested', $this->mail([$this->server()])->envelope()->subject);
        $this->assertSame(
            'Server sizing: 2 size changes suggested, 1 disk filling',
            $this->mail([$this->server(), $this->server(), $filling])->envelope()->subject
        );
        $this->assertSame(
            'Server sizing: no size changes suggested, 2 disks filling',
            $this->mail([$filling, $filling])->envelope()->subject
        );
    }

    public function test_it_goes_from_geeks_to_the_recipient_and_is_transactional(): void
    {
        $mail = $this->mail([$this->server()]);
        $envelope = $mail->envelope();

        $this->assertSame('sizing@example.org', $envelope->to[0]->address);
        $this->assertSame(config('freegle.mail.geeks_addr'), $envelope->from->address);
        $this->assertNull((fn () => $this->unsubscribeType())->call($mail));
    }

    public function test_the_whole_mail_renders_to_html_and_text(): void
    {
        $review = ['changes' => ['Move Alpha up to C-36.'], 'watch' => [], 'caveats' => ['Database memory is cache.']];
        $mail = $this->mail([$this->server()], $review, [
            'fleet' => ['monthly_list' => 720.0, 'monthly_paid' => 360.0, 'possible_saving_paid' => 12.5, 'possible_cost_paid' => 16.87],
            'unmatched_hosts' => ['stray'],
            'unattached_disks' => [
                ['name' => 'old-cache', 'size_gb' => 200, 'state' => 'built', 'machine_id' => null],
                ['name' => 'spare', 'size_gb' => 75, 'state' => 'not_built', 'machine_id' => 'vm_a'],
            ],
        ]);

        $html = $mail->render();

        $this->assertStringContainsString('<html', $html);
        $this->assertStringNotContainsString('<mj-', $html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('£360.00 a month', $html);
        $this->assertStringContainsString('would save', $html);
        $this->assertStringContainsString('would cost', $html);
        $this->assertStringContainsString('stray. Its readings are stored', $html);
        $this->assertStringContainsString('£12.50 a month', $html);
        $this->assertStringContainsString('Database memory is cache.', $html);
        $this->assertStringContainsString('old-cache, 200 GB, not attached to any server', $html);
        $this->assertStringContainsString('spare, 75 GB, attached but not built', $html);
        $this->assertStringContainsString('less our 50% discount', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(ServerSizingReportMail $mail, array $overrides = []): array
    {
        return array_merge([
            'previewText' => 'preview',
            'cards' => $mail->cards(),
            'review' => $mail->review,
            'monthlyPaid' => '£360.00',
            'monthlyList' => '£720.00',
            'possibleSaving' => null,
            'possibleCost' => null,
            'unmatchedHosts' => [],
            'discountPct' => 50,
            'historyDays' => 21,
            'minHistoryDays' => 14,
            'unattached' => [],
            'note' => null,
            'siteName' => 'Freegle',
        ], $overrides);
    }

    public function test_both_views_show_the_review_the_cards_and_the_basis_of_the_costs(): void
    {
        $review = ['changes' => ['Move Alpha up to C-36.'], 'watch' => ['The <root> disk.'], 'caveats' => []];
        $mail = $this->mail([$this->server()], $review);
        $data = $this->viewData($mail, ['possibleSaving' => '£22.50', 'possibleCost' => '£16.87', 'unmatchedHosts' => ['stray', 'other'],
            'unattached' => ['old-cache, 200 GB, not attached to any server']]);

        foreach (['emails.mjml.servers.sizing-report', 'emails.text.servers.sizing-report'] as $view) {
            $out = view($view, $data)->render();

            $this->assertStringContainsString('We pay about', $out);
            $this->assertStringContainsString('£360.00 a month', $out);
            $this->assertStringContainsString('The moves down suggested below would save', $out);
            $this->assertStringContainsString('The moves up suggested below would cost', $out);
            $this->assertStringContainsString('£16.87 a month', $out);
            $this->assertStringContainsString('stray, other. Its readings are stored', $out);
            $this->assertStringContainsString('Changes worth making', $out);
            $this->assertStringContainsString('Move Alpha up to C-36.', $out);
            $this->assertStringNotContainsString('What the rules cannot see', $out);
            $this->assertStringContainsString('Move up to C-36: costs £16.87 more a month', $out);
            $this->assertStringContainsString('8.43 on 8 processors (1.05 each)', $out);
            $this->assertStringContainsString('30 Nov: load 1.05 per processor', $out);
            $this->assertStringContainsString('old-cache, 200 GB, not attached to any server', $out);
            $this->assertStringContainsString('less our 50% discount', $out);
            $this->assertStringContainsString('written by AI', $out);
            $this->assertStringNotContainsString('readings so far', $out);
        }

        // The HTML copy escapes what the AI wrote; the plain-text copy keeps it as typed.
        $this->assertStringContainsString('The &lt;root&gt; disk.', view('emails.mjml.servers.sizing-report', $data)->render());
        $this->assertStringContainsString('The <root> disk.', view('emails.text.servers.sizing-report', $data)->render());
    }

    public function test_both_views_say_when_there_is_no_review_no_saving_no_discount_and_little_history(): void
    {
        $mail = $this->mail([$this->server()]);
        $data = $this->viewData($mail, ['discountPct' => 0, 'historyDays' => 1, 'minHistoryDays' => 14]);

        foreach (['emails.mjml.servers.sizing-report', 'emails.text.servers.sizing-report'] as $view) {
            $out = view($view, $data)->render();

            $this->assertStringContainsString('The AI review was not available this week', $out);
            $this->assertStringContainsString('No change that saves money is suggested this week', $out);
            $this->assertStringContainsString('There is 1 day of readings so far.', $out);
            $this->assertStringContainsString('Readings only began in the last day.', view($view, ['historyDays' => 0] + $data)->render());
            $this->assertStringContainsString('There are 5 days of readings so far.', view($view, ['historyDays' => 5] + $data)->render());
            $this->assertStringNotContainsString('discount', $out);
            $this->assertStringNotContainsString('Disks to look at', $out);
            $this->assertStringNotContainsString('Correction.', $out);
            $this->assertStringContainsString('The costs were too low.', view($view, ['note' => 'The costs were too low.'] + $data)->render());
            $this->assertStringContainsString('Correction.', view($view, ['note' => 'The costs were too low.'] + $data)->render());
            $this->assertStringNotContainsString('would cost', $out);
            $this->assertStringNotContainsString('not a server Katapult lists', $out);
        }
    }
}
