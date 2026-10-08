<?php

namespace Tests\Unit\Services;

use App\Services\AuthorityStatsService;
use App\Support\ReuseBenefit;
use Illuminate\Support\Facades\DB;
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

    public function test_get_authority_keeps_a_group_that_only_touches_the_boundary_at_its_share(): void
    {
        $this->seedAuthorityScenario();

        // 2% of this group is inside the authority - Southend against Essex County. The stats
        // page keeps it and counts 2% of its figures; only the Partnerships list drops it.
        $this->insertGroup(900104, 'grazegrp', 'Graze Group', 'POLYGON((0.98 10.5, 1.98 10.5, 1.98 10.6, 0.98 10.6, 0.98 10.5))');

        $overlaps = array_column($this->service->getAuthority($this->authorityId)['groups'], 'overlap', 'namedisplay');

        $this->assertArrayHasKey('Graze Group', $overlaps);
        $this->assertEqualsWithDelta(0.02, $overlaps['Graze Group'], 0.005);
    }

    public function test_get_authority_reports_how_much_of_the_authority_each_group_covers(): void
    {
        $this->seedAuthorityScenario();

        $coverage = array_column($this->service->getAuthority($this->authorityId)['groups'], 'coverage', 'namedisplay');

        // Full is 1x1 inside a 2x2 authority; Half has 0.5x1 of it inside.
        $this->assertEqualsWithDelta(0.25, $coverage['Full Group'], 0.001);
        $this->assertEqualsWithDelta(0.125, $coverage['Half Group'], 0.001);
    }

    public function test_significant_groups_drops_a_group_that_only_grazes_the_boundary(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGroup(900104, 'grazegrp', 'Graze Group', 'POLYGON((0.98 10.5, 1.98 10.5, 1.98 10.6, 0.98 10.6, 0.98 10.5))');

        $names = array_column($this->service->getSignificantGroups($this->authorityId)['groups'], 'namedisplay');

        $this->assertNotContains('Graze Group', $names);
        $this->assertContains('Full Group', $names);
        $this->assertContains('Half Group', $names);
    }

    public function test_significant_groups_keeps_a_small_group_wholly_inside_a_big_authority(): void
    {
        $this->seedAuthorityScenario();

        // Covers a hundredth of the authority, but all of it is inside: Castle Point in Essex.
        $this->insertGroup(900106, 'tinygrp', 'Tiny Group', 'POLYGON((0 10.5, 0.2 10.5, 0.2 10.7, 0 10.7, 0 10.5))');

        $names = array_column($this->service->getSignificantGroups($this->authorityId)['groups'], 'namedisplay');

        $this->assertContains('Tiny Group', $names);
    }

    public function test_significant_groups_keeps_a_big_group_that_covers_a_small_authority(): void
    {
        $this->seedAuthorityScenario();

        // Much bigger than the authority and holds all of it, so only about 1% of the group is
        // inside: a small council in a large community.
        $this->insertGroup(900107, 'biggrp', 'Big Group', 'POLYGON((-10 5, 10 5, 10 20, -10 20, -10 5))');

        $names = array_column($this->service->getSignificantGroups($this->authorityId)['groups'], 'namedisplay');

        $this->assertContains('Big Group', $names);
    }

    public function test_is_significant_overlap_uses_the_same_five_percent_in_either_direction(): void
    {
        $this->assertTrue(AuthorityStatsService::isSignificantOverlap(0.05, 0.0));
        $this->assertTrue(AuthorityStatsService::isSignificantOverlap(0.0, 0.05));
        $this->assertFalse(AuthorityStatsService::isSignificantOverlap(0.049, 0.049));
    }

    public function test_compute_report_for_a_partnership_reports_exactly_its_communities(): void
    {
        $this->seedAuthorityScenario();

        // A community well outside the boundary that the council sponsors anyway.
        $outsideId = 900105;
        $this->insertGroup($outsideId, 'outsidegrp', 'Outside Group', 'POLYGON((5 10.5, 6 10.5, 6 11.5, 5 11.5, 5 10.5))');
        $this->stat($outsideId, self::AMC, '2025-06-30', 30);
        $this->stat($outsideId, self::WEIGHT, '2025-06-15', 10);

        $partnershipId = DB::table('partnerships')->insertGetId([
            'authorityid' => $this->authorityId,
            'name' => 'Test Authority',
            'startdate' => '2025-01-01',
            'enddate' => '2025-12-31',
            'status' => 'Confirmed',
        ]);
        DB::table('partnerships_groups')->insert([
            ['partnershipid' => $partnershipId, 'groupid' => $this->groupFullId, 'source' => 'Boundary', 'overlap' => 1],
            ['partnershipid' => $partnershipId, 'groupid' => $this->groupHalfId, 'source' => 'Removed', 'overlap' => 0.5],
            ['partnershipid' => $partnershipId, 'groupid' => $this->groupTrivialId, 'source' => 'Boundary', 'overlap' => 1],
            ['partnershipid' => $partnershipId, 'groupid' => $outsideId, 'source' => 'Added', 'overlap' => null],
        ]);

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart, $partnershipId);

        $groups = [];
        foreach ($report['groups'] as $g) {
            $groups[$g['namedisplay']] = $g;
        }

        // The page's list, no more and no less: the left-out community is gone, and the
        // trivial one stays because the council was told it is covered.
        $this->assertSame(['Full Group', 'Outside Group', 'Trivial Group'], array_keys($groups));
        // Added by hand counts in full.
        $this->assertSame([0, 0, 30], $groups['Outside Group']['members']);
        $this->assertSame([0.0, 0.0, 10.0], $groups['Outside Group']['weight']);
        $this->assertSame(['members' => 160, 'weight' => 160.0, 'outcomes' => 15.0], $report['totals'][2]);
    }

    public function test_compute_report_returns_null_for_unknown_authority(): void
    {
        $this->assertNull($this->service->computeReport(987654321, $this->quarterStart));
    }
}
