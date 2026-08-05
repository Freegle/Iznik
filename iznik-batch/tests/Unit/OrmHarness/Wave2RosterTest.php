<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the INSERT IGNORE conversions in RepairRosterCommand.
 *
 * These are the half of the IGNORE pattern that IS convertible: Laravel gives
 * insertOrIgnore() a first-class form, where UPDATE IGNORE has none (see the
 * keep-raw rule of that name). The modifier is compared rather than assumed -
 * assertInsertOrIgnore renders through compileInsertOrIgnore, so dropping the
 * IGNORE would change the golden and fail, which matters because here it is
 * the difference between skipping a roster row that already exists and
 * aborting the repair.
 */
class Wave2RosterTest extends TestCase
{
    // INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?)
    private const SITE_ROSTER_MEMBER = '211ed1484c4b';
    private const SITE_ROSTER_MOD = '99d42e86b84c';

    public function test_roster_inserts(): void
    {
        $build = fn () => [
            DB::table('chat_roster'),
            ['chatid' => 1, 'userid' => 2],
        ];

        GoldenSql::assertInsertOrIgnore(self::SITE_ROSTER_MEMBER, $build);
        GoldenSql::assertInsertOrIgnore(self::SITE_ROSTER_MOD, $build);
    }
}
