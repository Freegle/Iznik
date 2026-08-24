<?php

namespace Tests\Unit\Services;

use App\Services\AuthorityStatsService;
use App\Support\ReuseBenefit;
use Tests\Support\SeedsAuthorityStats;
use Tests\TestCase;

class AuthorityStatsServiceTest extends TestCase
{
    use SeedsAuthorityStats;

    private AuthorityStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuthorityStatsService();
    }

    public function test_get_months_returns_the_containing_quarter(): void
    {
        $months = $this->service->getMonths('2025-05-15');

        $this->assertCount(3, $months);
        $this->assertSame(['start' => '2025-04-01', 'end' => '2025-05-01', 'formatted' => 'Apr-25'], $months[0]);
        $this->assertSame(['start' => '2025-05-01', 'end' => '2025-06-01', 'formatted' => 'May-25'], $months[1]);
        $this->assertSame(['start' => '2025-06-01', 'end' => '2025-07-01', 'formatted' => 'Jun-25'], $months[2]);
    }

    public function test_get_quarter_number(): void
    {
        $this->assertSame(1, $this->service->getQuarterNumber('2025-02-10'));
        $this->assertSame(2, $this->service->getQuarterNumber('2025-05-15'));
        $this->assertSame(3, $this->service->getQuarterNumber('2025-08-01'));
        $this->assertSame(4, $this->service->getQuarterNumber('2025-12-31'));
    }

    public function test_reuse_benefit_inflation_and_clamping(): void
    {
        $this->assertSame(93.4, ReuseBenefit::getCPI(2011));
        $this->assertSame(133.9, ReuseBenefit::getCPI(2024));
        $this->assertSame(93.4, ReuseBenefit::getCPI(2000), 'clamps below the range');
        $this->assertSame(133.9, ReuseBenefit::getCPI(2100), 'clamps above the range');

        $this->assertSame(711.0, ReuseBenefit::getBenefitPerTonne(2011));
        $this->assertSame(1019.0, ReuseBenefit::getBenefitPerTonne(2024));
        $this->assertSame(0.51, ReuseBenefit::CO2_PER_TONNE);
    }

    public function test_get_authority_returns_name_and_overlapping_groups(): void
    {
        $this->seedAuthorityScenario();

        $authority = $this->service->getAuthority($this->authorityId);

        $this->assertNotNull($authority);
        $this->assertSame('Test Authority (B)', $authority['name']);

        $overlaps = [];
        foreach ($authority['groups'] as $g) {
            $overlaps[$g['namedisplay']] = $g['overlap'];
        }

        $this->assertCount(3, $authority['groups']);
        $this->assertEqualsWithDelta(1.0, $overlaps['Full Group'], 0.001);
        $this->assertEqualsWithDelta(0.5, $overlaps['Half Group'], 0.01);
        $this->assertEqualsWithDelta(1.0, $overlaps['Trivial Group'], 0.001);
    }

    public function test_get_multi_stats_aggregates_by_date(): void
    {
        $this->seedAuthorityScenario();

        $quarter = $this->service->getMultiStats([$this->groupFullId], '2025-04-01', '2025-07-01', [AuthorityStatsService::WEIGHT]);
        $weights = array_column($quarter[AuthorityStatsService::WEIGHT], 'count');
        $this->assertSame([50.0, 50.0, 200.0, 150.0], $weights);

        $april = $this->service->getMultiStats([$this->groupFullId], '2025-04-01', '2025-05-01', [AuthorityStatsService::WEIGHT]);
        $this->assertCount(2, $april[AuthorityStatsService::WEIGHT], 'end bound is exclusive');
    }

    public function test_get_shortlinks_and_click_history(): void
    {
        $this->seedAuthorityScenario();

        $links = $this->service->getShortlinks($this->groupFullId);
        $this->assertSame(['apple link', 'Zebra Link'], array_column($links, 'name'));

        $history = $this->service->getClickHistory(900801);
        $byDate = [];
        foreach ($history as $h) {
            $byDate[$h->date] = (int) $h->count;
        }
        $this->assertSame(['2025-04-05' => 2, '2025-05-10' => 3, '2025-07-01' => 9], $byDate);
    }

    public function test_get_by_authority_postcode_breakdown(): void
    {
        $this->seedAuthorityScenario();

        $postcodes = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-07-01');

        $this->assertArrayHasKey('AB1 2', $postcodes);
        $pc = $postcodes['AB1 2'];
        $this->assertSame(2, $pc['Offer']);
        $this->assertSame(1, $pc['Wanted']);
        $this->assertSame(2, $pc['Searches']);
        $this->assertSame(1, $pc['Outcomes']);
        $this->assertEqualsWithDelta(25.0, $pc['Weight'], 0.001);
    }

    public function test_get_stories_filters_to_authority_and_orders_by_date(): void
    {
        $this->seedAuthorityScenario();

        $stories = $this->service->getStories($this->authorityId, 10);

        $this->assertSame(['Newer inside', 'Older inside'], array_column($stories, 'headline'));
    }

    public function test_compute_report_end_to_end(): void
    {
        $this->seedAuthorityScenario();

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart);

        $this->assertNotNull($report);
        $this->assertSame('Test Authority (B)', $report['name']);
        $this->assertSame(2, $report['quarter']);
        $this->assertSame(0.51, $report['co2PerTonne']);
        $this->assertSame(ReuseBenefit::getBenefitPerTonne(), $report['benefitPerTonne']);

        // Monthly totals (the trivial group is excluded by the 3 kg cutoff).
        $this->assertSame(['members' => 120, 'weight' => 150.0, 'outcomes' => 14.0], $report['totals'][0]);
        $this->assertSame(['members' => 132, 'weight' => 250.0, 'outcomes' => 20.0], $report['totals'][1]);
        $this->assertSame(['members' => 145, 'weight' => 200.0, 'outcomes' => 19.0], $report['totals'][2]);

        // Per-group rows.
        $groups = [];
        foreach ($report['groups'] as $g) {
            $groups[$g['namedisplay']] = $g;
        }
        $this->assertCount(2, $groups);
        $this->assertSame([100, 110, 120], $groups['Full Group']['members']);
        $this->assertSame([100.0, 200.0, 150.0], $groups['Full Group']['weight']);
        $this->assertSame([10.0, 20.0, 15.0], $groups['Full Group']['outcomes']);
        // Half group's partial overlap is flagged with a trailing "*".
        $this->assertArrayHasKey('Half Group *', $groups);
        $this->assertSame([20, 22, 25], $groups['Half Group *']['members']);
        $this->assertSame([50.0, 50.0, 50.0], $groups['Half Group *']['weight']);
        $this->assertSame([4.0, 0.0, 4.0], $groups['Half Group *']['outcomes']);

        // Shortlinks, sorted case-insensitively, totalling clicks per month.
        $this->assertSame(['apple link', 'Middle', 'Zebra Link'], array_column($report['shortlinks'], 'name'));
        $clicks = [];
        foreach ($report['shortlinks'] as $l) {
            $clicks[$l['name']] = $l['clicks'];
        }
        $this->assertSame([2, 3, 0], $clicks['apple link']);
        $this->assertSame([0, 0, 5], $clicks['Middle']);
        $this->assertSame([1, 0, 4], $clicks['Zebra Link']);

        // Stories and postcode breakdown carried through.
        $this->assertSame(['Newer inside', 'Older inside'], array_column($report['stories'], 'headline'));
        $this->assertSame(2, $report['postcodes']['AB1 2']['Offer']);
    }

    public function test_compute_report_returns_null_for_unknown_authority(): void
    {
        $this->assertNull($this->service->computeReport(987654321, $this->quarterStart));
    }
}
