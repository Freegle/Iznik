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
        foreach ([[900521, 'AB1 2EF'], [900522, 'CD3 4GH']] as [$id, $name]) {
            DB::table('locations')->insert(['id' => $id, 'name' => $name, 'type' => 'Postcode', 'lat' => 11, 'lng' => 0]);
            $this->insertGeom('INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857))', [$id, 'POINT(0.1 11)']);
        }
        // A postcode district with no space, and a non-postcode location: both ignored.
        DB::table('locations')->insert(['id' => 900503, 'name' => 'NOSPACE', 'type' => 'Postcode', 'lat' => 11, 'lng' => 0]);
        DB::table('locations')->insert(['id' => 900504, 'name' => 'Some Road', 'type' => 'Road', 'lat' => 11, 'lng' => 0]);
        foreach ([900503, 900504] as $id) {
            $this->insertGeom('INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857))', [$id, 'POINT(0.2 11)']);
        }
        $this->message(900620, 'Offer', '2025-05-01 10:00:00', 900521);
        $this->message(900621, 'Wanted', '2025-05-02 10:00:00', 900522);
        $this->message(900622, 'Offer', '2025-05-03 10:00:00', 900503);
        $this->message(900623, 'Offer', '2025-05-04 10:00:00', 900504);
        DB::table('search_history')->insert(['term' => 'lamp', 'locationid' => 900522, 'date' => '2025-05-05 12:00:00']);
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
        // 4. Nothing at all: the UK centre, which is outside.
        $add('nothing known', []);
        // A real point at 0,0 is treated as "no location" by getStories even when inside a polygon.
        $add('zero zero', ['settings' => json_encode(['mylocation' => ['lat' => 0, 'lng' => 0]])]);

        $headlines = array_column($this->service->getStories($this->authorityId, 100), 'headline');
        $inside = array_values(array_intersect(array_keys($writers), $headlines));

        $this->assertEqualsCanonicalizing([
            'lastlocation inside',
            'setting beats lastlocation',
            'message latest wins',
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

    public function test_compute_report_for_an_empty_quarter(): void
    {
        $this->seedAuthorityScenario();

        $report = $this->service->computeReport($this->authorityId, '2025-01-10');

        $this->assertSame(1, $report['quarter']);
        $this->assertSame(['Jan-25', 'Feb-25', 'Mar-25'], array_column($report['months'], 'formatted'));
        // Members are counted by where they live, and the seeded ones joined in January.
        foreach ($report['totals'] as $total) {
            $this->assertSame(0.0, $total['weight']);
            $this->assertSame(0.0, $total['outcomes']);
        }
        $this->assertArrayNotHasKey('AB1 2', $report['postcodes']);
        $this->assertSame(date('Y'), $report['year']);
    }
}
