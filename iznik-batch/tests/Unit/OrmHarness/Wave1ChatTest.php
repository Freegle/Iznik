<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, chat: Layer 1 parity for the converted sites in
 * app/Console/Commands/Chat/MergeDuplicateChatRoomsCommand.php.
 *
 * Unlike ProvenSitesTest - which proves the harness works against sites that
 * deliberately stay raw - every site here HAS been converted: the production
 * call is now a query-builder chain, and this file is what holds that chain to
 * the SQL the raw statement used to send. The extractor reads these assertions
 * (parityTestedIds) and promotes each site to "converted" only because they
 * exist, so deleting one silently returns its site to unproven.
 *
 * The one raw statement left in that command is deliberate and NOT here: the
 * `latestmessage = (SELECT MAX(date) ...)` UPDATE is a correlated subquery in
 * the SET clause, which is a different conversion problem from these four and
 * belongs to a later wave rather than being rushed alongside them.
 */
class Wave1ChatTest extends TestCase
{
    // MergeDuplicateChatRoomsCommand.php: move messages to the canonical room.
    // UPDATE chat_messages SET chatid = ? WHERE chatid = ?
    private const SITE_MOVE_MESSAGES = '8a6d9645f2ff';

    // Read the duplicate room's roster before merging it.
    // SELECT userid, status, lastmsgseen, lastemailed, lastmsgemailed, lastip
    //   FROM chat_roster WHERE chatid = ?
    private const SITE_READ_ROSTER = 'edbb662bb021';

    // DELETE FROM chat_roster WHERE chatid = ?
    private const SITE_DELETE_ROSTER = '26237fedd99b';

    // DELETE FROM chat_rooms WHERE id = ?
    private const SITE_DELETE_ROOM = '193232bb689f';

    public function test_move_messages_to_canonical_room(): void
    {
        GoldenSql::assertUpdate(self::SITE_MOVE_MESSAGES, fn () => [
            DB::table('chat_messages')->where('chatid', 2),
            ['chatid' => 1],
        ]);
    }

    public function test_read_duplicate_roster(): void
    {
        GoldenSql::assert(self::SITE_READ_ROSTER, fn () => DB::table('chat_roster')
            ->select('userid', 'status', 'lastmsgseen', 'lastemailed', 'lastmsgemailed', 'lastip')
            ->where('chatid', 2));
    }

    public function test_delete_duplicate_roster(): void
    {
        GoldenSql::assertDelete(self::SITE_DELETE_ROSTER, fn () => DB::table('chat_roster')
            ->where('chatid', 2));
    }

    public function test_delete_duplicate_room(): void
    {
        GoldenSql::assertDelete(self::SITE_DELETE_ROOM, fn () => DB::table('chat_rooms')
            ->where('id', 2));
    }
}
