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

        // Monthly totals for the area: members living inside it at each month end (one
        // joined in May, one left in June), and the one gift completed inside it in June.
        $this->assertSame(['members' => 3, 'weight' => 0.0, 'outcomes' => 0.0], $report['totals'][0]);
        $this->assertSame(['members' => 4, 'weight' => 0.0, 'outcomes' => 0.0], $report['totals'][1]);
        $this->assertSame(['members' => 3, 'weight' => 25.0, 'outcomes' => 1.0], $report['totals'][2]);

        // Stories and postcode breakdown carried through.
        $this->assertSame(['Newer inside', 'Older inside'], array_column($report['stories'], 'headline'));
        $this->assertSame(2, $report['postcodes']['AB1 2']['Offer']);
    }

    public function test_compute_report_returns_null_for_unknown_authority(): void
    {
        $this->assertNull($this->service->computeReport(987654321, $this->quarterStart));
    }
}
