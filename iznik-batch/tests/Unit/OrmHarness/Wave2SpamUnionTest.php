<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the spam-room UNION in app/Services/ChatSpamService.php.
 *
 * Two things here would be silent behaviour changes if written the obvious way,
 * and the golden is what holds them:
 *
 *   ->union(), not ->unionAll(). The raw statement said UNION, which
 *   de-duplicates. A chat room where BOTH participants are spammers matches
 *   both arms, so unionAll() would warn the same room twice - visible to a
 *   member, not just to a report.
 *
 *   Two arms rather than one OR. The raw statement joined spam_users on user1
 *   in one arm and user2 in the other precisely so each arm can use the index
 *   on its own join column; collapsing them into a single join with an OR
 *   would render differently and is the kind of "simplification" that turns a
 *   pair of index lookups into a scan.
 */
class Wave2SpamUnionTest extends TestCase
{
    // SELECT ... FROM chat_rooms INNER JOIN spam_users ON user1 = spam_users.userid
    //   WHERE ... UNION SELECT ... ON user2 = spam_users.userid WHERE ...
    private const SITE_SPAM_ROOMS = '28717f6b9519';

    public function test_spam_rooms_union(): void
    {
        GoldenSql::assert(self::SITE_SPAM_ROOMS, function () {
            $arm = fn (string $side) => DB::table('chat_rooms')
                ->select('chat_rooms.id', 'spam_users.userid as spammer_id', 'user1', 'user2')
                ->join('spam_users', $side, '=', 'spam_users.userid')
                ->where('latestmessage', '>=', '2026-01-01 00:00:00')
                ->where('flaggedspam', 0)
                ->where('spam_users.collection', 'Spammer');

            return $arm('user1')->union($arm('user2'));
        });
    }
}
