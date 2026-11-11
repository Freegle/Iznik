<?php

namespace Tests\Unit\Services;

use App\Services\AuthorityStatsService;
use App\Support\ReuseBenefit;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsAuthorityStats;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Extra scenarios for AuthorityStatsService, beside AuthorityStatsServiceTest.
 *
 * The pure helpers are table driven; the report methods use the same seeded
 * authority scenario (a 2x2 degree square near lat 11) plus extra rows.
 */
class AuthorityStatsServiceCoverageTest extends TestCase
{
    use SeedsAuthorityStats;

    private AuthorityStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuthorityStatsService();
    }

    // --- pure helpers --------------------------------------------------------

    public static function monthsProvider(): array
    {
        return [
            'first day of Q1' => ['2025-01-01', ['2025-01-01', '2025-02-01', '2025-03-01', '2025-04-01'], ['Jan-25', 'Feb-25', 'Mar-25']],
            'last day of Q1' => ['2025-03-31', ['2025-01-01', '2025-02-01', '2025-03-01', '2025-04-01'], ['Jan-25', 'Feb-25', 'Mar-25']],
            'first day of Q2' => ['2025-04-01', ['2025-04-01', '2025-05-01', '2025-06-01', '2025-07-01'], ['Apr-25', 'May-25', 'Jun-25']],
            'last day of Q2' => ['2025-06-30', ['2025-04-01', '2025-05-01', '2025-06-01', '2025-07-01'], ['Apr-25', 'May-25', 'Jun-25']],
            'middle of Q3' => ['2025-08-15', ['2025-07-01', '2025-08-01', '2025-09-01', '2025-10-01'], ['Jul-25', 'Aug-25', 'Sep-25']],
            'last day of Q4 rolls the year in the end bound' => ['2025-12-31', ['2025-10-01', '2025-11-01', '2025-12-01', '2026-01-01'], ['Oct-25', 'Nov-25', 'Dec-25']],
            'leap day' => ['2024-02-29', ['2024-01-01', '2024-02-01', '2024-03-01', '2024-04-01'], ['Jan-24', 'Feb-24', 'Mar-24']],
            'time of day is ignored' => ['2025-05-15 23:59:59', ['2025-04-01', '2025-05-01', '2025-06-01', '2025-07-01'], ['Apr-25', 'May-25', 'Jun-25']],
            'other strtotime format' => ['15 November 2023', ['2023-10-01', '2023-11-01', '2023-12-01', '2024-01-01'], ['Oct-23', 'Nov-23', 'Dec-23']],
        ];
    }

    #[DataProvider('monthsProvider')]
    public function test_get_months_table(string $input, array $bounds, array $formatted): void
    {
        $months = $this->service->getMonths($input);

        $this->assertCount(3, $months);
        foreach ($months as $i => $month) {
            $this->assertSame($bounds[$i], $month['start']);
            $this->assertSame($bounds[$i + 1], $month['end'], 'each end is the next start');
            $this->assertSame($formatted[$i], $month['formatted']);
        }
    }

    public function test_get_months_relative_string_is_a_quarter_in_the_past(): void
    {
        $months = $this->service->getMonths('3 months ago');

        $this->assertCount(3, $months);
        $this->assertSame(1, (int) date('j', strtotime($months[0]['start'])));
        // The first month of any quarter is 1, 4, 7 or 10.
        $this->assertContains((int) date('n', strtotime($months[0]['start'])), [1, 4, 7, 10]);
    }

    public static function quarterProvider(): array
    {
        $rows = [];
        foreach ([1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 2, 7 => 3, 8 => 3, 9 => 3, 10 => 4, 11 => 4, 12 => 4] as $month => $quarter) {
            $rows["month $month"] = [sprintf('2025-%02d-15', $month), $quarter];
        }
        $rows['1 Jan'] = ['2025-01-01', 1];
        $rows['31 Mar'] = ['2025-03-31', 1];
        $rows['1 Apr'] = ['2025-04-01', 2];
        $rows['30 Sep'] = ['2025-09-30', 3];
        $rows['1 Oct'] = ['2025-10-01', 4];
        $rows['leap day'] = ['2024-02-29', 1];

        return $rows;
    }

    #[DataProvider('quarterProvider')]
    public function test_get_quarter_number_table(string $date, int $expected): void
    {
        $this->assertSame($expected, $this->service->getQuarterNumber($date));
    }

    public function test_benefit_and_co2_come_from_reuse_benefit(): void
    {
        $this->assertSame(ReuseBenefit::getBenefitPerTonne(), $this->service->getBenefitPerTonne());
        $this->assertIsFloat($this->service->getBenefitPerTonne());
        $this->assertGreaterThan(0.0, $this->service->getBenefitPerTonne());
        $this->assertSame(ReuseBenefit::CO2_PER_TONNE, $this->service->getCo2PerTonne());
        $this->assertIsFloat($this->service->getCo2PerTonne());
    }

    public static function significantProvider(): array
    {
        return [
            'both zero' => [0.0, 0.0, false],
            'just under in both' => [0.0499, 0.0499, false],
            'overlap exactly at threshold' => [0.05, 0.0, true],
            'coverage exactly at threshold' => [0.0, 0.05, true],
            'both over' => [1.0, 1.0, true],
            'overlap high, coverage low' => [0.9, 0.001, true],
            'overlap low, coverage high' => [0.001, 0.9, true],
        ];
    }

    #[DataProvider('significantProvider')]
    public function test_is_significant_overlap_table(float $overlap, float $coverage, bool $expected): void
    {
        $this->assertSame($expected, AuthorityStatsService::isSignificantOverlap($overlap, $coverage));
    }

    // --- getAuthority / getSignificantGroups --------------------------------

    public function test_get_authority_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->service->getAuthority(987654321));
    }

    public function test_get_significant_groups_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->service->getSignificantGroups(987654321));
    }

    public function test_get_authority_with_no_overlapping_groups(): void
    {
        $this->insertGeom(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, 3857))',
            [900010, 'Lonely Authority', 'POLYGON((50 50, 51 50, 51 51, 50 51, 50 50))']
        );

        $this->assertSame(['name' => 'Lonely Authority', 'groups' => []], $this->service->getAuthority(900010));
    }

    public function test_get_authority_only_keeps_published_onmap_freegle_groups(): void
    {
        $this->seedAuthorityScenario();
        $wkt = 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))';

        $this->insertGroup(900110, 'okgrp', 'Ok Group', $wkt);
        $this->insertGroup(900111, 'unpubgrp', 'Unpublished Group', $wkt);
        $this->insertGroup(900112, 'offmapgrp', 'Off Map Group', $wkt);
        $this->insertGroup(900113, 'ufgrp', 'Not Freegle Group', $wkt);
        DB::table('groups')->where('id', 900111)->update(['publish' => 0]);
        DB::table('groups')->where('id', 900112)->update(['onmap' => 0]);
        DB::table('groups')->where('id', 900113)->update(['type' => 'Other']);

        $names = array_column($this->service->getAuthority($this->authorityId)['groups'], 'namedisplay');

        $this->assertContains('Ok Group', $names);
        $this->assertNotContains('Unpublished Group', $names);
        $this->assertNotContains('Off Map Group', $names);
        $this->assertNotContains('Not Freegle Group', $names);
    }

    public function test_get_authority_name_falls_back_to_short_name(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGroup(900114, 'shortonly', '', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        DB::table('groups')->where('id', 900114)->update(['namefull' => null]);

        $names = array_column($this->service->getAuthority($this->authorityId)['groups'], 'namedisplay', 'id');

        $this->assertSame('shortonly', $names[900114]);
    }

    public function test_get_authority_group_with_identical_geometry_is_exactly_one(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGroup(900115, 'samegrp', 'Same Shape Group', 'POLYGON((-1 10, 1 10, 1 12, -1 12, -1 10))');

        $groups = array_column($this->service->getAuthority($this->authorityId)['groups'], null, 'namedisplay');

        $this->assertSame(1.0, $groups['Same Shape Group']['overlap']);
        $this->assertSame(1.0, $groups['Same Shape Group']['coverage']);
    }

    public function test_get_authority_ignores_groups_that_only_share_an_edge_or_are_elsewhere(): void
    {
        $this->seedAuthorityScenario();
        // Shares only the eastern edge: the intersection is a LINESTRING, not a polygon.
        $this->insertGroup(900116, 'edgegrp', 'Edge Group', 'POLYGON((1 10, 2 10, 2 12, 1 12, 1 10))');
        // Shares only a corner: the intersection is a POINT.
        $this->insertGroup(900117, 'cornergrp', 'Corner Group', 'POLYGON((1 12, 2 12, 2 13, 1 13, 1 12))');
        // Nowhere near.
        $this->insertGroup(900118, 'fargrp', 'Far Group', 'POLYGON((30 30, 31 30, 31 31, 30 31, 30 30))');

        $names = array_column($this->service->getAuthority($this->authorityId)['groups'], 'namedisplay');

        $this->assertNotContains('Edge Group', $names);
        $this->assertNotContains('Corner Group', $names);
        $this->assertNotContains('Far Group', $names);
    }

    public function test_get_authority_overlap_just_above_ninety_five_percent_rounds_to_one(): void
    {
        $this->seedAuthorityScenario();
        // 0.98 of this group's width lies inside the authority (x from 0.02 to 1).
        $this->insertGroup(900119, 'almostgrp', 'Almost Group', 'POLYGON((0.02 10.5, 1.0408 10.5, 1.0408 11.5, 0.02 11.5, 0.02 10.5))');

        $groups = array_column($this->service->getAuthority($this->authorityId)['groups'], null, 'namedisplay');

        $this->assertSame(1.0, $groups['Almost Group']['overlap']);
    }

    // --- getPartnershipGroups ------------------------------------------------

    private function makePartnership(): int
    {
        return DB::table('partnerships')->insertGetId([
            'authorityid' => $this->authorityId,
            'name' => 'Coverage Partnership',
            'startdate' => '2025-01-01',
            'enddate' => '2025-12-31',
            'status' => 'Confirmed',
        ]);
    }

    public function test_get_partnership_groups_empty_for_unknown_partnership(): void
    {
        $this->assertSame([], $this->service->getPartnershipGroups(987654321));
    }

    public function test_get_partnership_groups_ordering_names_overlap_and_removed(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGroup(900120, 'zzz', 'A Full Name', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $this->insertGroup(900121, 'aaa', 'Z Full Name', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $this->insertGroup(900122, 'mmm', '', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $this->insertGroup(900123, 'qqq', 'Gone Group', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $this->insertGroup(900124, 'rrr', 'Exactly Threshold', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $pid = $this->makePartnership();
        DB::table('partnerships_groups')->insert([
            ['partnershipid' => $pid, 'groupid' => 900120, 'source' => 'Boundary', 'overlap' => 0.5],
            ['partnershipid' => $pid, 'groupid' => 900121, 'source' => 'Boundary', 'overlap' => 0.96],
            ['partnershipid' => $pid, 'groupid' => 900122, 'source' => 'Added', 'overlap' => null],
            ['partnershipid' => $pid, 'groupid' => 900123, 'source' => 'Removed', 'overlap' => 1],
            ['partnershipid' => $pid, 'groupid' => 900124, 'source' => 'Boundary', 'overlap' => 0.95],
        ]);

        $groups = $this->service->getPartnershipGroups($pid);

        // Ordered by short name (aaa, mmm, rrr, zzz); Removed is dropped.
        $this->assertSame([900121, 900122, 900124, 900120], array_column($groups, 'id'));
        // Full name when there is one, otherwise the short name.
        $this->assertSame(['Z Full Name', 'mmm', 'Exactly Threshold', 'A Full Name'], array_column($groups, 'namedisplay'));
        // Above 0.95 rounds up to 1, 0.95 itself stays, null means 1.
        $this->assertSame([1.0, 1.0, 0.95, 0.5], array_column($groups, 'overlap'));
        $this->assertContainsOnly('int', array_column($groups, 'id'));
    }

    // --- getMultiStats -------------------------------------------------------

    public function test_get_multi_stats_empty_group_list_gives_empty_entry_per_type(): void
    {
        $this->seedAuthorityScenario();

        $stats = $this->service->getMultiStats([], '2025-04-01', '2025-07-01', [AuthorityStatsService::WEIGHT, AuthorityStatsService::OUTCOMES]);

        $this->assertSame([AuthorityStatsService::WEIGHT => [], AuthorityStatsService::OUTCOMES => []], $stats);
    }

    public function test_get_multi_stats_no_types_gives_empty_array(): void
    {
        $this->assertSame([], $this->service->getMultiStats([$this->groupFullId], '2025-04-01', '2025-07-01', []));
    }

    public function test_get_multi_stats_unknown_type_and_no_matching_rows(): void
    {
        $this->seedAuthorityScenario();

        $stats = $this->service->getMultiStats([$this->groupFullId], '2025-04-01', '2025-07-01', ['NoSuchType']);
        $this->assertSame(['NoSuchType' => []], $stats);

        $none = $this->service->getMultiStats([$this->groupFullId], '2020-01-01', '2020-02-01', [AuthorityStatsService::WEIGHT]);
        $this->assertSame([AuthorityStatsService::WEIGHT => []], $none);
    }

    public function test_get_multi_stats_sums_several_groups_by_date_in_date_order(): void
    {
        $this->seedAuthorityScenario();

        $stats = $this->service->getMultiStats(
            [$this->groupFullId, $this->groupHalfId, $this->groupTrivialId],
            '2025-04-01',
            '2025-05-01',
            [AuthorityStatsService::WEIGHT]
        );

        // 10 Apr: full 50 + half 100 + trivial 2; 20 Apr: full 50.
        $this->assertSame([
            ['date' => '2025-04-10', 'count' => 152.0],
            ['date' => '2025-04-20', 'count' => 50.0],
        ], $stats[AuthorityStatsService::WEIGHT]);
    }

    public function test_get_multi_stats_start_is_inclusive_and_dates_are_normalised(): void
    {
        $this->seedAuthorityScenario();

        // A stat exactly on the start day is counted; a time of day on the bounds is dropped.
        $stats = $this->service->getMultiStats([$this->groupFullId], '2025-04-10 18:30:00', '2025-04-20 01:00:00', [AuthorityStatsService::WEIGHT]);

        $this->assertSame([['date' => '2025-04-10', 'count' => 50.0]], $stats[AuthorityStatsService::WEIGHT]);
    }

    public function test_get_multi_stats_returns_each_requested_type_separately(): void
    {
        $this->seedAuthorityScenario();

        $stats = $this->service->getMultiStats(
            [$this->groupFullId],
            '2025-04-01',
            '2025-07-01',
            [AuthorityStatsService::APPROVED_MEMBER_COUNT, AuthorityStatsService::OUTCOMES]
        );

        $this->assertSame([90.0, 100.0, 110.0, 120.0], array_column($stats[AuthorityStatsService::APPROVED_MEMBER_COUNT], 'count'));
        $this->assertSame([5.0, 5.0, 20.0, 15.0], array_column($stats[AuthorityStatsService::OUTCOMES], 'count'));
        $this->assertArrayNotHasKey(AuthorityStatsService::WEIGHT, $stats);
    }

    // --- getByAuthority ------------------------------------------------------

    public function test_get_by_authority_with_unknown_authority_is_empty(): void
    {
        $this->seedAuthorityScenario();

        $this->assertSame([], $this->service->getByAuthority([987654321], '2025-04-01', '2025-07-01'));
    }

    public function test_get_by_authority_with_no_authority_ids_is_refused(): void
    {
        // Characterises current behaviour: the In expression rejects an empty list, so an empty
        // authority list throws rather than returning []. computeReport always passes one id.
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->service->getByAuthority([], '2025-04-01', '2025-06-30');
        } finally {
            // Whatever failed, do not leave the temporary table behind for the next test.
            DB::statement('DROP TEMPORARY TABLE IF EXISTS pc');
        }
    }

    public function test_get_by_authority_date_range_is_inclusive_to_the_end_of_the_last_day(): void
    {
        $this->seedAuthorityScenario();
        // Late on the last day of the range, and one second into the next day.
        $this->message(900610, 'Offer', '2025-06-30 23:59:58', 900500);
        $this->message(900611, 'Offer', '2025-07-01 00:00:01', 900500);

        $postcodes = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');

        $this->assertSame(3, $postcodes['AB1 2']['Offer'], 'two seeded offers plus the one on the last day');
    }

    public function test_get_by_authority_outside_the_range_gives_no_rows(): void
    {
        $this->seedAuthorityScenario();

        $this->assertSame([], $this->service->getByAuthority([$this->authorityId], '2024-01-01', '2024-03-31'));
    }

    public function test_get_by_authority_groups_by_outward_code_and_skips_non_postcodes(): void
    {
        $this->seedAuthorityScenario();

        // A second full postcode in the same outward code, and one in a different one.
        foreach ([[900501, 'AB1 2EF'], [900502, 'CD3 4GH']] as [$id, $name]) {
            DB::table('locations')->insert(['id' => $id, 'name' => $name, 'type' => 'Postcode', 'lat' => 11, 'lng' => 0]);
            $this->insertGeom('INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857))', [$id, 'POINT(0.1 11)']);
        }
        // A postcode district with no space, and a non-postcode location: both ignored.
        DB::table('locations')->insert(['id' => 900503, 'name' => 'NOSPACE', 'type' => 'Postcode', 'lat' => 11, 'lng' => 0]);
        DB::table('locations')->insert(['id' => 900504, 'name' => 'Some Road', 'type' => 'Road', 'lat' => 11, 'lng' => 0]);
        foreach ([900503, 900504] as $id) {
            $this->insertGeom('INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857))', [$id, 'POINT(0.2 11)']);
        }
        $this->message(900620, 'Offer', '2025-05-01 10:00:00', 900501);
        $this->message(900621, 'Wanted', '2025-05-02 10:00:00', 900502);
        $this->message(900622, 'Offer', '2025-05-03 10:00:00', 900503);
        $this->message(900623, 'Offer', '2025-05-04 10:00:00', 900504);
        DB::table('search_history')->insert(['term' => 'lamp', 'locationid' => 900502, 'date' => '2025-05-05 12:00:00']);
        DB::table('search_history')->insert(['term' => 'nospace', 'locationid' => 900503, 'date' => '2025-05-05 12:00:00']);

        $postcodes = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');

        $this->assertSame(['AB1 2', 'CD3 4'], array_keys($postcodes), 'AB1 2EF joins AB1 2CD; the others are dropped');
        $this->assertSame(3, $postcodes['AB1 2']['Offer']);
        $this->assertSame(1, $postcodes['CD3 4']['Wanted']);
        $this->assertSame(1, $postcodes['CD3 4']['Searches']);
        $this->assertSame(0, $postcodes['CD3 4']['Offer']);
        $this->assertSame(0, $postcodes['CD3 4']['Outcomes']);
        $this->assertSame(0.0, $postcodes['CD3 4']['Weight']);
    }

    public function test_get_by_authority_counts_received_but_not_other_outcomes(): void
    {
        $this->seedAuthorityScenario();
        $this->message(900630, 'Wanted', '2025-05-10 10:00:00', 900500);
        $this->message(900631, 'Offer', '2025-05-11 10:00:00', 900500);
        DB::table('messages_outcomes')->insert([
            ['msgid' => 900630, 'outcome' => 'Received', 'timestamp' => '2025-05-12 10:00:00'],
            ['msgid' => 900631, 'outcome' => 'Withdrawn', 'timestamp' => '2025-05-12 10:00:00'],
        ]);

        $postcodes = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');

        // The seeded Taken plus the new Received; Withdrawn is not counted.
        $this->assertSame(2, $postcodes['AB1 2']['Outcomes']);
    }

    public function test_get_by_authority_uses_average_weight_for_items_without_one(): void
    {
        $this->seedAuthorityScenario();
        DB::table('items')->insert(['id' => 900701, 'name' => 'mystery thing', 'weight' => null, 'popularity' => 1]);
        $this->message(900640, 'Offer', '2025-05-20 10:00:00', 900500);
        DB::table('messages_items')->insert(['msgid' => 900640, 'itemid' => 900701]);
        DB::table('messages_outcomes')->insert(['msgid' => 900640, 'outcome' => 'Taken', 'timestamp' => '2025-05-21 10:00:00']);

        // The popularity weighted mean of every item that has a weight.
        $avg = (float) DB::table('items')
            ->whereNotNull('weight')->where('weight', '!=', 0)
            ->selectRaw('SUM(popularity * weight) / SUM(popularity) AS average')
            ->value('average');

        $postcodes = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');

        // Seeded chair is 25 kg; the unweighted item takes the average.
        $this->assertEqualsWithDelta(25.0 + $avg, $postcodes['AB1 2']['Weight'], 0.001);
    }

    public function test_get_by_authority_leaves_no_temporary_table_behind_and_can_repeat(): void
    {
        $this->seedAuthorityScenario();

        $first = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');
        $second = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');

        $this->assertSame($first, $second);
    }

    public function test_get_by_authority_combines_several_authorities(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGeom(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, 3857))',
            [900011, 'Second Authority', 'POLYGON((20 20, 22 20, 22 22, 20 22, 20 20))']
        );
        DB::table('locations')->insert(['id' => 900505, 'name' => 'EF5 6IJ', 'type' => 'Postcode', 'lat' => 21, 'lng' => 21]);
        $this->insertGeom('INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857))', [900505, 'POINT(21 21)']);
        $this->message(900650, 'Offer', '2025-05-01 10:00:00', 900505);

        $one = $this->service->getByAuthority([$this->authorityId], '2025-04-01', '2025-06-30');
        $both = $this->service->getByAuthority([$this->authorityId, 900011], '2025-04-01', '2025-06-30');

        $this->assertArrayNotHasKey('EF5 6', $one);
        $this->assertSame(1, $both['EF5 6']['Offer']);
        $this->assertSame($one['AB1 2'], $both['AB1 2']);
    }

    // --- shortlinks and clicks ----------------------------------------------

    public function test_get_shortlinks_for_group_without_any_is_empty_and_ids_are_ints(): void
    {
        $this->seedAuthorityScenario();

        $this->assertSame([], $this->service->getShortlinks($this->groupTrivialId));
        $this->assertSame([['id' => 900803, 'name' => 'Middle']], $this->service->getShortlinks($this->groupHalfId));
    }

    public function test_get_click_history_unknown_link_is_empty_and_days_are_grouped_in_order(): void
    {
        $this->seedAuthorityScenario();

        $this->assertSame([], $this->service->getClickHistory(987654321));

        $history = $this->service->getClickHistory(900802);
        $this->assertSame(['2025-04-15', '2025-06-25'], array_column($history, 'date'));
        $this->assertSame([1, 4], array_map('intval', array_column($history, 'count')));
    }

    // --- getStories ----------------------------------------------------------

    public function test_get_stories_with_no_stories_at_all_is_empty(): void
    {
        $this->seedAuthorityScenario();
        DB::table('users_stories')->delete();

        $this->assertSame([], $this->service->getStories($this->authorityId));
    }

    public function test_get_stories_for_unknown_authority_is_empty(): void
    {
        $this->seedAuthorityScenario();

        $this->assertSame([], $this->service->getStories(987654321));
    }

    public function test_get_stories_respects_the_limit_and_keeps_newest_first(): void
    {
        $this->seedAuthorityScenario();

        $this->assertSame(['Newer inside'], array_column($this->service->getStories($this->authorityId, 1), 'headline'));
        $this->assertSame(['Newer inside', 'Older inside'], array_column($this->service->getStories($this->authorityId, 2), 'headline'));
        $this->assertSame(['Newer inside', 'Older inside'], array_column($this->service->getStories($this->authorityId, 50), 'headline'));
    }

    public function test_get_stories_skips_unreviewed_private_and_anonymous_stories(): void
    {
        $this->seedAuthorityScenario();
        DB::table('users_stories')->insert([
            ['id' => 900311, 'userid' => 900201, 'headline' => 'Unreviewed', 'story' => 'x', 'public' => 1, 'reviewed' => 0, 'date' => '2025-06-20 08:00:00'],
            ['id' => 900312, 'userid' => 900201, 'headline' => 'Private', 'story' => 'x', 'public' => 0, 'reviewed' => 1, 'date' => '2025-06-21 08:00:00'],
            ['id' => 900313, 'userid' => null, 'headline' => 'No author', 'story' => 'x', 'public' => 1, 'reviewed' => 1, 'date' => '2025-06-22 08:00:00'],
        ]);

        $headlines = array_column($this->service->getStories($this->authorityId, 10), 'headline');

        $this->assertSame(['Newer inside', 'Older inside'], $headlines);
    }

    public function test_get_stories_returns_headline_and_story_text(): void
    {
        $this->seedAuthorityScenario();

        $stories = $this->service->getStories($this->authorityId, 1);

        $this->assertSame([['headline' => 'Newer inside', 'story' => 'Newer inside body']], $stories);
    }

    /**
     * Each story writer is placed by a different fallback in resolveUserLatLngs.
     * The expected value says whether that fallback puts them inside the
     * authority (a 2x2 degree square around lat 11, lng 0).
     */
    public function test_get_stories_places_each_writer_by_the_right_fallback(): void
    {
        $this->seedAuthorityScenario();
        DB::table('locations')->insert([
            ['id' => 900510, 'name' => 'Inside place', 'type' => 'Point', 'lat' => 11, 'lng' => 0],
            ['id' => 900511, 'name' => 'Outside place', 'type' => 'Point', 'lat' => 40, 'lng' => 40],
        ]);

        $n = 900400;
        $writers = [];
        $add = function (string $key, array $user, ?callable $extra = null) use (&$n, &$writers) {
            $id = ++$n;
            DB::table('users')->insert(['id' => $id] + $user);
            DB::table('users_stories')->insert([
                'id' => $id, 'userid' => $id, 'headline' => $key, 'story' => 'body', 'public' => 1, 'reviewed' => 1,
                'date' => '2025-07-01 08:00:00',
            ]);
            if ($extra) {
                $extra($id);
            }
            $writers[$key] = $id;
        };

        // 2. users.lastlocation.
        $add('lastlocation inside', ['lastlocation' => 900510]);
        $add('lastlocation outside', ['lastlocation' => 900511]);
        // 1 beats 2: the setting wins over lastlocation.
        $add('setting beats lastlocation', ['settings' => json_encode(['mylocation' => ['lat' => 11, 'lng' => 0]]), 'lastlocation' => 900511]);
        // Malformed or partial settings are ignored.
        $add('settings without lng', ['settings' => json_encode(['mylocation' => ['lat' => 11]])]);
        $add('settings not json', ['settings' => 'not json at all']);
        // TrashNothing members never use settings.mylocation, so fall to the UK centre (outside).
        $add('tn member setting ignored', ['settings' => json_encode(['mylocation' => ['lat' => 11, 'lng' => 0]]), 'tnuserid' => 4242]);
        // 3. Most recent geolocated message: the later one wins.
        $add('message latest wins', [], function (int $id) {
            DB::table('messages')->insert([
                ['id' => $id * 10 + 1, 'type' => 'Offer', 'arrival' => '2025-01-01 10:00:00', 'fromuser' => $id, 'lat' => 40, 'lng' => 40, 'message' => ''],
                ['id' => $id * 10 + 2, 'type' => 'Offer', 'arrival' => '2025-02-01 10:00:00', 'fromuser' => $id, 'lat' => 11, 'lng' => 0, 'message' => ''],
            ]);
        });
        $add('message ungeolocated ignored', [], function (int $id) {
            DB::table('messages')->insert([
                ['id' => $id * 10 + 1, 'type' => 'Offer', 'arrival' => '2025-01-01 10:00:00', 'fromuser' => $id, 'lat' => null, 'lng' => null, 'message' => ''],
            ]);
        });
        // 4. Group membership location (the group sits at 11, 0): the most recently added wins.
        $add('group membership', [], function (int $id) {
            DB::table('groups')->where('id', $this->groupFullId)->update(['lat' => 40, 'lng' => 40]);
            DB::table('memberships')->insert([
                ['userid' => $id, 'groupid' => $this->groupFullId, 'added' => '2025-01-01 00:00:00'],
                ['userid' => $id, 'groupid' => $this->groupHalfId, 'added' => '2025-02-01 00:00:00'],
            ]);
        });
        // 5. Nothing at all: the UK centre, which is outside.
        $add('nothing known', []);
        // A real point at 0,0 is treated as "no location" by getStories even when inside a polygon.
        $add('zero zero', ['settings' => json_encode(['mylocation' => ['lat' => 0, 'lng' => 0]])]);

        $headlines = array_column($this->service->getStories($this->authorityId, 100), 'headline');
        $inside = array_values(array_intersect(array_keys($writers), $headlines));

        $this->assertEqualsCanonicalizing([
            'lastlocation inside',
            'setting beats lastlocation',
            'message latest wins',
            'group membership',
        ], $inside);
    }

    public function test_get_stories_does_not_count_a_point_at_zero_zero_even_inside_the_polygon(): void
    {
        $this->insertGeom(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, 3857))',
            [900012, 'Null Island Authority', 'POLYGON((-1 -1, 1 -1, 1 1, -1 1, -1 -1))']
        );
        DB::table('users')->insert(['id' => 900450, 'settings' => json_encode(['mylocation' => ['lat' => 0, 'lng' => 0]])]);
        DB::table('users')->insert(['id' => 900451, 'settings' => json_encode(['mylocation' => ['lat' => 0.5, 'lng' => 0]])]);
        DB::table('users_stories')->insert([
            ['id' => 900450, 'userid' => 900450, 'headline' => 'At zero', 'story' => 's', 'public' => 1, 'reviewed' => 1, 'date' => '2025-07-01 08:00:00'],
            ['id' => 900451, 'userid' => 900451, 'headline' => 'Near zero', 'story' => 's', 'public' => 1, 'reviewed' => 1, 'date' => '2025-07-01 07:00:00'],
        ]);

        $headlines = array_column($this->service->getStories(900012, 10), 'headline');

        $this->assertSame(['Near zero'], $headlines);
    }

    // --- computeReport -------------------------------------------------------

    public function test_compute_report_drops_a_group_with_enough_weight_but_no_members_in_the_last_month(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGroup(900130, 'nomemgrp', 'No Members Group', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $this->stat(900130, self::WEIGHT, '2025-05-15', 40);
        $this->stat(900130, self::AMC, '2025-04-30', 25);
        DB::table('shortlinks')->insert(['id' => 900810, 'name' => 'Hidden link', 'type' => 'Group', 'groupid' => 900130]);

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart);

        $names = array_column($report['groups'], 'namedisplay');
        $this->assertNotContains('No Members Group', $names);
        $this->assertNotContains('Hidden link', array_column($report['shortlinks'], 'name'));
        // Its weight and earlier members still count towards the totals.
        $this->assertSame(145, $report['totals'][0]['members']);
        $this->assertSame(290.0, $report['totals'][1]['weight']);
    }

    public function test_compute_report_for_an_empty_quarter(): void
    {
        $this->seedAuthorityScenario();

        $report = $this->service->computeReport($this->authorityId, '2025-01-10');

        $this->assertSame(1, $report['quarter']);
        $this->assertSame(['Jan-25', 'Feb-25', 'Mar-25'], array_column($report['months'], 'formatted'));
        foreach ($report['totals'] as $total) {
            $this->assertSame(['members' => 0, 'weight' => 0.0, 'outcomes' => 0.0], $total);
        }
        $this->assertSame([], $report['groups']);
        $this->assertSame([], $report['shortlinks']);
        $this->assertArrayNotHasKey('AB1 2', $report['postcodes']);
        $this->assertSame(date('Y'), $report['year']);
    }

    public function test_compute_report_for_a_partnership_with_no_communities(): void
    {
        $this->seedAuthorityScenario();
        $pid = $this->makePartnership();

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart, $pid);

        $this->assertSame([], $report['groups']);
        $this->assertSame([], $report['shortlinks']);
        $this->assertSame(['members' => 0, 'weight' => 0.0, 'outcomes' => 0.0], $report['totals'][1]);
    }

    public function test_compute_report_partnership_group_with_no_stats_still_gets_a_zero_row(): void
    {
        $this->seedAuthorityScenario();
        $this->insertGroup(900131, 'quietgrp', 'Quiet Group', 'POLYGON((-0.3 10.7, 0.3 10.7, 0.3 11.3, -0.3 11.3, -0.3 10.7))');
        $pid = $this->makePartnership();
        DB::table('partnerships_groups')->insert(['partnershipid' => $pid, 'groupid' => 900131, 'source' => 'Added', 'overlap' => 0.4]);

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart, $pid);

        $this->assertCount(1, $report['groups']);
        $this->assertSame('Quiet Group *', $report['groups'][0]['namedisplay'], 'overlap below 1 is starred');
        $this->assertSame([0, 0, 0], $report['groups'][0]['members']);
        $this->assertSame([0.0, 0.0, 0.0], $report['groups'][0]['weight']);
        $this->assertSame([0.0, 0.0, 0.0], $report['groups'][0]['outcomes']);
    }

    public function test_compute_report_uses_the_latest_member_count_in_each_month(): void
    {
        $this->seedAuthorityScenario();

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart);
        $groups = array_column($report['groups'], null, 'namedisplay');

        // April has two member counts (90 on the 1st, 100 on the 30th): the later one is used.
        $this->assertSame(100, $groups['Full Group']['members'][0]);
    }

    public function test_compute_report_sorts_shortlinks_case_insensitively_across_groups(): void
    {
        $this->seedAuthorityScenario();
        DB::table('shortlinks')->insert(['id' => 900811, 'name' => 'BANANA', 'type' => 'Group', 'groupid' => $this->groupFullId]);
        DB::table('shortlinks')->insert(['id' => 900812, 'name' => 'cherry', 'type' => 'Group', 'groupid' => $this->groupHalfId]);

        $report = $this->service->computeReport($this->authorityId, $this->quarterStart);

        $this->assertSame(['apple link', 'BANANA', 'cherry', 'Middle', 'Zebra Link'], array_column($report['shortlinks'], 'name'));
        $this->assertSame([0, 0, 0], array_column($report['shortlinks'], 'clicks', 'name')['BANANA']);
    }
}
