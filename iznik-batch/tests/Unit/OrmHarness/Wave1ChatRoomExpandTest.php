<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1: Layer 1 parity for converted sites in app/Models/ChatRoom.php and
 * app/Services/Ripple/ExpandService.php.
 *
 * ad8e424f4855 is one of the three sites ProvenSitesTest already proved a
 * builder chain WOULD match, back when Phase B was demonstrating the harness
 * against sites that stayed raw. The production call is now actually converted,
 * so the site moves from "proven possible" to "proven and done".
 */
class Wave1ChatRoomExpandTest extends TestCase
{
    // SELECT id FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = ? FOR UPDATE
    private const SITE_LOCK_USER2MOD = 'e9968cc6fc40';

    // INSERT INTO memberships_history (...) VALUES (?, ?, 'Approved', 1, 1)
    private const SITE_MEMBERSHIP_HISTORY = '273df11c1838';

    // SELECT COUNT(DISTINCT userid) AS n FROM chat_messages WHERE refmsgid = ? AND type = 'Interested'
    private const SITE_DISTINCT_REPLIERS = 'd73229723d30';

    public function test_lock_existing_user2mod_room(): void
    {
        GoldenSql::assert(self::SITE_LOCK_USER2MOD, fn () => DB::table('chat_rooms')
            ->select('id')
            ->where('user1', 1)
            ->where('groupid', 2)
            ->where('chattype', 'User2Mod')
            ->lockForUpdate());
    }

    public function test_insert_membership_history(): void
    {
        GoldenSql::assertInsert(self::SITE_MEMBERSHIP_HISTORY, fn () => [
            DB::table('memberships_history'),
            [
                'userid' => 1,
                'groupid' => 2,
                'collection' => 'Approved',
                'processingrequired' => 1,
                'rippled' => 1,
            ],
        ]);
    }

    public function test_distinct_replier_count(): void
    {
        GoldenSql::assert(self::SITE_DISTINCT_REPLIERS, function () {
            $q = DB::table('chat_messages')
                ->where('refmsgid', 1)
                ->where('type', 'Interested')
                ->distinct();
            // What ->count('userid') assembles, without executing it.
            $q->aggregate = ['function' => 'count', 'columns' => ['userid']];

            return $q;
        });
    }
}
