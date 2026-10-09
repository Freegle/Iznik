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

    // SELECT messages.id ... LEFT JOIN lovejunk ... AND NOT EXISTS (...)
    private const SITE_NEW = '8784a573032d';

    // SELECT DISTINCT messages.id FROM messages_outcomes INNER JOIN ... x4
    private const SITE_OUTCOMES = 'a792f4c8557c';

    // SELECT mp.userid, u.ljuserid FROM messages_promises mp INNER JOIN users u ...
    private const SITE_PROMISES = '63bbb51470ed';

    // buildPayload's five-LEFT-join row assembly.
    private const SITE_PAYLOAD = 'b3867b4b17c4';

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

    /**
     * The anti-join is the point: leftJoin plus "lovejunk.msgid IS NULL" finds
     * messages NOT yet sent to LoveJunk. An inner join returns exactly the
     * opposite set - every message already sent - which would re-send the lot.
     *
     * NOT EXISTS maps to whereNotExists. Laravel writes "select *" inside the
     * subquery where the raw statement wrote "select 1"; the select list of an
     * EXISTS is not evaluated, so that is a recorded approved diff rather than
     * a difference.
     */
    public function test_new_messages_sweep(): void
    {
        GoldenSql::assert(self::SITE_NEW, fn () => DB::table('messages')
            ->select('messages.id')
            ->leftJoin('lovejunk', 'lovejunk.msgid', '=', 'messages.id')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('groups', 'groups.id', '=', 'messages_groups.groupid')
            ->where('messages.arrival', '>=', '2026-01-01')
            ->where('messages.type', 'Offer')
            ->whereNull('lovejunk.msgid')
            ->where('messages_groups.collection', 'Approved')
            ->where('groups.onlovejunk', 1)
            ->whereNotExists(fn ($q) => $q->from('messages_bulk_items')
                ->whereColumn('messages_bulk_items.msgid', 'messages.id'))
            ->orderBy('messages.arrival'));
    }

    public function test_messages_with_outcomes(): void
    {
        GoldenSql::assert(self::SITE_OUTCOMES, fn () => DB::table('messages_outcomes')
            ->distinct()
            ->select('messages.id')
            ->join('messages', 'messages.id', '=', 'messages_outcomes.msgid')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('lovejunk', 'lovejunk.msgid', '=', 'messages_outcomes.msgid')
            ->join('groups', 'groups.id', '=', 'messages_groups.groupid')
            ->where('messages_outcomes.timestamp', '>=', '2026-01-01')
            ->where('messages.type', 'Offer')
            ->where('lovejunk.success', 1)
            ->whereNull('lovejunk.deleted')
            ->where('lovejunk.status', 'LIKE', '{%')
            ->where('groups.onlovejunk', 1)
            ->orderBy('messages.arrival'));
    }

    public function test_promises_with_lovejunk_users(): void
    {
        GoldenSql::assert(self::SITE_PROMISES, fn () => DB::table('messages_promises as mp')
            ->select('mp.userid', 'u.ljuserid')
            ->join('users as u', 'u.id', '=', 'mp.userid')
            ->where('mp.msgid', 1)
            ->whereNotNull('u.ljuserid'));
    }

    /**
     * Five LEFT joins, every one of them deliberate: a message with no item,
     * no location or no area must still come back with nulls. Any one of these
     * as an inner join silently drops messages from the LoveJunk feed rather
     * than failing visibly. Note also that `la` joins `l`, not `m` - it is the
     * AREA of the message's location, so re-pointing it at m.locationid would
     * quietly return the location twice.
     */
    public function test_payload_row(): void
    {
        GoldenSql::assert(self::SITE_PAYLOAD, fn () => DB::table('messages as m')
            ->select(
                'm.id', 'm.textbody', 'm.fromuser', 'm.locationid', 'm.lat', 'm.lng',
                'm.sourceheader', 'm.subject', 'm.type',
                'i.name as item',
                'u.fullname', 'u.firstname', 'u.lastname',
                'l.name as postcode',
                'la.name as area'
            )
            ->leftJoin('messages_items as mi', 'mi.msgid', '=', 'm.id')
            ->leftJoin('items as i', 'i.id', '=', 'mi.itemid')
            ->leftJoin('users as u', 'u.id', '=', 'm.fromuser')
            ->leftJoin('locations as l', 'l.id', '=', 'm.locationid')
            ->leftJoin('locations as la', 'la.id', '=', 'l.areaid')
            ->where('m.id', 1)
            ->limit(1));
    }
}
