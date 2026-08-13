<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, batch 4: the two reach-table verification counts, the group
 * maintenance location sweep, and the remaining two gift-aid consent variants.
 *
 * Two builder methods earn their place here, and both would be silent bugs if
 * written as a plain where():
 *
 *   whereColumn('lat', '<', 'lng') - compares two COLUMNS. A plain
 *   where('lat', '<', 'lng') binds the string 'lng' and matches nothing.
 *
 *   whereDate('timestamp', '=', $d) - emits date(`timestamp`) = ?, which is
 *   what the raw statement said. Comparing the raw column to a date string
 *   instead would change which rows match around midnight.
 */
class Wave1Batch4Test extends TestCase
{
    // SELECT COUNT(*) AS n FROM rippling_reach_old
    private const SITE_COUNT_OLD = 'ae68a80c3ae5';

    // SELECT COUNT(*) AS n FROM rippling_reach
    private const SITE_COUNT_NEW = 'a2bf5d4cf838';

    // SELECT DISTINCT locations.id, lat, lng, name FROM locations
    //   WHERE lat < lng AND locations.name NOT LIKE 'BF%'
    private const SITE_SUSPECT_LOCATIONS = 'd5c7a6502341';

    // UPDATE users_donations SET giftaidconsent = 1 WHERE ... AND date(timestamp) = ?
    private const SITE_CONSENT_THIS = 'a61080618cba';

    // ... AND date(timestamp) >= ?
    private const SITE_CONSENT_FUTURE = '3dc022a277c1';

    public function test_reach_table_counts(): void
    {
        GoldenSql::assert(self::SITE_COUNT_OLD, function () {
            $q = DB::table('rippling_reach_old');
            $q->aggregate = ['function' => 'count', 'columns' => ['*']];

            return $q;
        });

        GoldenSql::assert(self::SITE_COUNT_NEW, function () {
            $q = DB::table('rippling_reach');
            $q->aggregate = ['function' => 'count', 'columns' => ['*']];

            return $q;
        });
    }

    public function test_suspect_locations(): void
    {
        GoldenSql::assert(self::SITE_SUSPECT_LOCATIONS, fn () => DB::table('locations')
            ->distinct()
            ->select('locations.id', 'lat', 'lng', 'name')
            ->whereColumn('lat', '<', 'lng')
            ->where('locations.name', 'NOT LIKE', 'BF%'));
    }

    public function test_giftaid_consent_date_variants(): void
    {
        GoldenSql::assertUpdate(self::SITE_CONSENT_THIS, fn () => [
            DB::table('users_donations')
                ->where('userid', 1)
                ->where('giftaidconsent', 0)
                ->where('timestamp', '>=', '2020-01-01')
                ->whereDate('timestamp', '=', '2026-01-01'),
            ['giftaidconsent' => 1],
        ]);

        GoldenSql::assertUpdate(self::SITE_CONSENT_FUTURE, fn () => [
            DB::table('users_donations')
                ->where('userid', 1)
                ->where('giftaidconsent', 0)
                ->where('timestamp', '>=', '2020-01-01')
                ->whereDate('timestamp', '>=', '2026-01-01'),
            ['giftaidconsent' => 1],
        ]);
    }
}
