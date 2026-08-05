<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the LoveJunk edited-message sweep - a five-table join.
 *
 * whereColumn carries the predicate that makes this query mean anything:
 * messages_edits.timestamp > lovejunk.timestamp is "edited since we last told
 * LoveJunk about it". Written as a plain where() it would bind the string
 * 'lovejunk.timestamp' and compare a timestamp against text, which MySQL would
 * evaluate rather than reject - so it would run, return the wrong rows, and
 * look fine.
 */
class Wave2LoveJunkTest extends TestCase
{
    // SELECT DISTINCT messages.id, lovejunk.status FROM messages INNER JOIN ... x4
    private const SITE_EDITED = '0c92b9505326';

    public function test_edited_messages_sweep(): void
    {
        GoldenSql::assert(self::SITE_EDITED, fn () => DB::table('messages')
            ->distinct()
            ->select('messages.id', 'lovejunk.status')
            ->join('lovejunk', 'lovejunk.msgid', '=', 'messages.id')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('messages_edits', 'messages_edits.msgid', '=', 'messages.id')
            ->join('groups', 'groups.id', '=', 'messages_groups.groupid')
            ->where('messages.arrival', '>=', '2026-01-01')
            ->whereColumn('messages_edits.timestamp', '>', 'lovejunk.timestamp')
            ->where('messages.type', 'Offer')
            ->where('messages_groups.collection', 'Approved')
            ->where('groups.onlovejunk', 1)
            ->orderBy('messages.arrival'));
    }
}
