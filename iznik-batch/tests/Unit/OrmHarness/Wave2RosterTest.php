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

    // The same statement again, in ChatRoom::getOrCreateUser2Mod - once for the
    // member, once per group moderator.
    private const SITE_ROSTER_MEMBER_2 = '4f88701abf8b';
    private const SITE_ROSTER_MOD_2 = '0b34a7e081a2';

    // UPDATE chat_roster SET lastmsgemailed = ?
    //   WHERE chatid = ? AND userid = ? AND (lastmsgemailed IS NULL OR lastmsgemailed < ?)
    private const SITE_MARK_EMAILED = '4efc9c6192c6';

    // INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?)
    private const SITE_LINK_ITEM = 'a420de83662c';

    public function test_roster_inserts(): void
    {
        $build = fn () => [
            DB::table('chat_roster'),
            ['chatid' => 1, 'userid' => 2],
        ];

        GoldenSql::assertInsertOrIgnore(self::SITE_ROSTER_MEMBER, $build);
        GoldenSql::assertInsertOrIgnore(self::SITE_ROSTER_MOD, $build);
        GoldenSql::assertInsertOrIgnore(self::SITE_ROSTER_MEMBER_2, $build);
        GoldenSql::assertInsertOrIgnore(self::SITE_ROSTER_MOD_2, $build);
    }

    /**
     * The grouped OR is the whole point of this one. Written as a flat
     * ->orWhere() it would bind to the entire WHERE rather than to the pair,
     * and the statement would update every roster row whose lastmsgemailed is
     * behind - across all chats, not just this one. The golden catches it:
     * the parentheses appear in the rendered SQL.
     */
    public function test_mark_roster_emailed(): void
    {
        GoldenSql::assertUpdate(self::SITE_MARK_EMAILED, fn () => [
            DB::table('chat_roster')
                ->where('chatid', 1)
                ->where('userid', 2)
                ->where(function ($q) {
                    $q->whereNull('lastmsgemailed')
                      ->orWhere('lastmsgemailed', '<', 3);
                }),
            ['lastmsgemailed' => 3],
        ]);
    }

    public function test_link_item_to_message(): void
    {
        GoldenSql::assertInsertOrIgnore(self::SITE_LINK_ITEM, fn () => [
            DB::table('messages_items'),
            ['msgid' => 1, 'itemid' => 2],
        ]);
    }
}
