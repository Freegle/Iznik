<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the GDPR export's chat lookup - the last non-dynamic raw statement.
 *
 * A UNION inside a derived table, joined back. Two details the golden holds:
 * ->union() rather than unionAll (a chat matching both arms must appear once,
 * or the export duplicates it), and the grouped OR in the second arm, which
 * flat would bind against that arm's whole WHERE and pull in every chat the
 * user ever moderated.
 */
class Wave2ExportTest extends TestCase
{
    private const SITE_EXPORT_CHATS = 'a2a0b695276b';

    public function test_export_chat_ids(): void
    {
        GoldenSql::assert(self::SITE_EXPORT_CHATS, function () {
            $sub = DB::table('chat_roster')
                ->distinct()
                ->select('chatid')
                ->where('userid', 1)
                ->union(
                    DB::table('chat_messages')
                        ->distinct()
                        ->select('chatid')
                        ->where(function ($q) {
                            $q->where('userid', 1)->orWhere('reviewedby', 1);
                        })
                );

            return DB::table('chat_rooms')
                ->distinct()
                ->select('chat_rooms.id')
                ->joinSub($sub, 't', 't.chatid', '=', 'chat_rooms.id')
                ->orderBy('chat_rooms.id');
        });
    }
}
