<?php

namespace Tests\Unit\Services\AzureArchive;

use App\Services\AzureArchive\BudgetFigures;
use App\Services\AzureArchive\EgressBudget;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The egress budget is what keeps the copy inside Azure's free 100 GB a month. Each case is one
 * way it could let a byte through that it should not, or one figure it must refuse to guess.
 */
class EgressBudgetTest extends TestCase
{
    private const GB = 1000000000;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-11-11T12:00:00Z');
    }

    private function budget(int $runMaxGb = 10, bool $costRequired = true): EgressBudget
    {
        return new EgressBudget(80 * self::GB, 1.25, $runMaxGb * self::GB, 180, $costRequired);
    }

    private function figures(array $over = []): BudgetFigures
    {
        $v = array_merge([
            'azureEgress31d' => 10.0 * self::GB,
            'azureNewestPoint' => $this->now->subMinutes(30),
            'ourBytes31d' => 0,
            'ourBytes24h' => 0,
            'ourBytesRecent' => 0,
            'bandwidthCost' => 0.0,
            'billedEgressGb' => 5.0,
            'costError' => null,
            'metricsError' => null,
            'latchedStop' => null,
        ], $over);

        return new BudgetFigures(
            $this->now,
            $v['azureEgress31d'],
            $v['azureNewestPoint'],
            $v['ourBytes31d'],
            $v['ourBytes24h'],
            $v['ourBytesRecent'],
            $v['bandwidthCost'],
            $v['billedEgressGb'],
            $v['costError'],
            $v['metricsError'],
            $v['latchedStop'],
        );
    }

    public function test_a_day_gets_a_31st_of_what_members_views_leave_under_the_ceiling(): void
    {
        // Members' views 10 GB, reserved at 12.5 GB; (80 - 12.5) / 31 = 2.177 GB a day.
        $decision = $this->budget()->decide($this->figures());

        $this->assertTrue($decision->allowed());
        $this->assertEqualsWithDelta(67.5 * self::GB / 31, $decision->allowedBytes, 1);
    }

    public function test_what_was_fetched_in_the_last_day_comes_off_the_days_share(): void
    {
        $daily = 67.5 * self::GB / 31;
        $decision = $this->budget()->decide($this->figures([
            'azureEgress31d' => 10.0 * self::GB + $daily,
            'ourBytes31d' => (int) $daily,
            'ourBytes24h' => (int) $daily,
        ]));

        $this->assertSame(0, $decision->allowedBytes);
        $this->assertSame([], $decision->refusals, 'a spent day is pacing, not a refusal');
    }

    public function test_a_run_never_takes_more_than_its_cap(): void
    {
        $decision = $this->budget(runMaxGb: 1)->decide($this->figures());

        $this->assertSame(1 * self::GB, $decision->allowedBytes);
    }

    public function test_the_window_close_to_the_ceiling_leaves_only_the_headroom(): void
    {
        // Pace would allow 2 GB, but the last 31 days already hold 79.5 GB.
        $decision = $this->budget()->decide($this->figures([
            'azureEgress31d' => 79.5 * self::GB,
            'ourBytes31d' => (int) (75 * self::GB),
        ]));

        $this->assertEqualsWithDelta(0.5 * self::GB, $decision->allowedBytes, 1);
    }

    public function test_our_fetches_azure_has_not_reported_yet_still_count(): void
    {
        $decision = $this->budget()->decide($this->figures([
            'azureEgress31d' => 79.0 * self::GB,
            'ourBytes31d' => (int) (60 * self::GB),
            'ourBytesRecent' => (int) (0.8 * self::GB),
        ]));

        $this->assertEqualsWithDelta(0.2 * self::GB, $decision->allowedBytes, 1);
    }

    public function test_our_own_fetches_are_not_mistaken_for_members_views(): void
    {
        // Azure's 30 GB includes 19 GB of ours (20 in the window, 1 too recent for Azure to have).
        $decision = $this->budget()->decide($this->figures([
            'azureEgress31d' => 30.0 * self::GB,
            'ourBytes31d' => 20 * self::GB,
            'ourBytesRecent' => 1 * self::GB,
        ]));

        $this->assertEqualsWithDelta(11 * self::GB, $decision->figures['site_bytes_31d'], 1);
    }

    public function test_members_views_alone_near_the_ceiling_leave_nothing(): void
    {
        $decision = $this->budget()->decide($this->figures(['azureEgress31d' => 65.0 * self::GB]));

        // 65 GB reserved at 81.25 GB, more than the ceiling.
        $this->assertSame(0, $decision->allowedBytes);
    }

    public function test_unreadable_egress_refuses(): void
    {
        $decision = $this->budget()->decide($this->figures(['azureEgress31d' => null, 'metricsError' => 'HTTP 403']));

        $this->assertSame(0, $decision->allowedBytes);
        $this->assertStringContainsString('HTTP 403', implode(' ', $decision->refusals));
    }

    public function test_no_egress_points_at_all_refuses(): void
    {
        $decision = $this->budget()->decide($this->figures(['azureEgress31d' => 0.0, 'azureNewestPoint' => null]));

        $this->assertFalse($decision->allowed());
    }

    public function test_stale_egress_refuses(): void
    {
        $decision = $this->budget()->decide($this->figures(['azureNewestPoint' => $this->now->subHours(4)]));

        $this->assertFalse($decision->allowed());
        $this->assertStringContainsString('stale', implode(' ', $decision->refusals));
    }

    public function test_an_unreadable_bill_refuses_unless_the_check_is_switched_off_on_purpose(): void
    {
        $figures = $this->figures(['bandwidthCost' => null, 'billedEgressGb' => null, 'costError' => 'not supported for this offer']);

        $this->assertFalse($this->budget()->decide($figures)->allowed());
        $this->assertTrue($this->budget(costRequired: false)->decide($figures)->allowed());
    }

    public function test_any_bandwidth_charge_refuses_and_asks_for_a_latched_stop(): void
    {
        $decision = $this->budget()->decide($this->figures(['bandwidthCost' => 0.01]));

        $this->assertSame(0, $decision->allowedBytes);
        $this->assertNotNull($decision->latch);
        $this->assertStringContainsString('0.01', $decision->latch);
    }

    public function test_a_bandwidth_charge_latches_even_when_the_check_is_optional(): void
    {
        $decision = $this->budget(costRequired: false)->decide($this->figures(['bandwidthCost' => 2.5]));

        $this->assertNotNull($decision->latch);
        $this->assertFalse($decision->allowed());
    }

    public function test_billed_egress_at_the_ceiling_refuses(): void
    {
        // 75 billed GB, read as GiB, is 80.5e9 bytes.
        $decision = $this->budget()->decide($this->figures(['billedEgressGb' => 75.0]));

        $this->assertFalse($decision->allowed());
    }

    public function test_billed_egress_limits_the_allowance_with_our_last_day_on_top(): void
    {
        // 73 GiB billed = 78.38e9, so 1.62 GB left; our last day (1 GB) may not be billed yet: 0.62 GB.
        $decision = $this->budget()->decide($this->figures([
            'billedEgressGb' => 73.0,
            'ourBytes24h' => 0,
            'azureEgress31d' => 1.0 * self::GB,
        ]));
        $limited = $this->budget()->decide($this->figures([
            'billedEgressGb' => 73.0,
            'ourBytes24h' => 1 * self::GB,
            'azureEgress31d' => 1.0 * self::GB,
            'ourBytes31d' => 1 * self::GB,
        ]));

        $this->assertEqualsWithDelta(80 * self::GB - 73 * 1073741824, $decision->allowedBytes, 1);
        $this->assertEqualsWithDelta(80 * self::GB - 73 * 1073741824 - self::GB, $limited->allowedBytes, 1);
    }

    public function test_a_latched_stop_refuses_whatever_the_figures(): void
    {
        $decision = $this->budget()->decide($this->figures(['latchedStop' => 'Azure charged 1.00 (since 2026-11-10)']));

        $this->assertFalse($decision->allowed());
        $this->assertStringContainsString('--clear-stop', implode(' ', $decision->refusals));
    }

    public function test_figures_are_reported_even_when_refused(): void
    {
        $decision = $this->budget()->decide($this->figures(['latchedStop' => 'x']));

        $this->assertEqualsWithDelta(10 * self::GB, $decision->figures['site_bytes_31d'], 1);
        $this->assertGreaterThan(0, $decision->figures['daily_share']);
    }

    public function test_from_config_reads_the_ceiling_in_decimal_gigabytes(): void
    {
        $this->assertSame(80 * self::GB, (new EgressBudget(80 * self::GB, 1.25, 1, 180, true))->ceilingBytes());
    }

    public function test_a_charge_in_the_last_billing_period_latches_too(): void
    {
        // Cost Management lags: a charge from the last days of a period shows after it closes.
        $f = $this->figures();
        $figures = new BudgetFigures($f->now, $f->azureEgress31d, $f->azureNewestPoint, 0, 0, 0, 0.0, 5.0, null, null, null, 0.3);

        $decision = $this->budget()->decide($figures);

        $this->assertFalse($decision->allowed());
        $this->assertStringContainsString('last billing period', (string) $decision->latch);
    }

    public function test_an_unreadable_last_period_refuses_when_the_cost_check_is_required(): void
    {
        $f = $this->figures();
        $figures = new BudgetFigures($f->now, $f->azureEgress31d, $f->azureNewestPoint, 0, 0, 0, 0.0, 5.0, null, null, null, null);

        $this->assertFalse($this->budget()->decide($figures)->allowed());
        $this->assertTrue($this->budget(costRequired: false)->decide($figures)->allowed());
    }

    public function test_trailing_zero_hours_are_not_taken_as_reported(): void
    {
        $newest = \App\Services\AzureArchive\FigureGatherer::newestReported([
            '2026-11-11T08:00:00Z' => 5.0e6,
            '2026-11-11T09:00:00Z' => 4.0e6,
            '2026-11-11T10:00:00Z' => 0.0,
            '2026-11-11T11:00:00Z' => 0.0,
        ]);

        $this->assertSame('2026-11-11T09:00:00+00:00', $newest?->toIso8601String());
        $this->assertNull(\App\Services\AzureArchive\FigureGatherer::newestReported(['2026-11-11T08:00:00Z' => 0.0]));
    }
}
