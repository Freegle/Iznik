<?php

namespace Tests\Unit\OrmHarness;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the first JOIN conversions, in app/Services/ChatExpectedService.php.
 *
 * Wave 2 is where Layer 1 alone starts being weaker evidence than it was for
 * the single-table statements. Identical rendered SQL still means identical
 * rows here, because these are plain INNER JOINs with no grouping - the join
 * type, the ON clause and every predicate are compared literally by the golden.
 * That stops being true once GROUP BY or an aggregate is involved, where two
 * statements can render differently and return the same rows, or render
 * similarly and not; those sites want ResultParity::assertForSite rather than
 * this, and are deliberately not batched in here.
 *
 * The join type matters and is checked: ->join() is an INNER JOIN in Laravel,
 * so a ->leftJoin() slip would change which rows come back and would show up
 * in the golden as `left join`.
 */
class Wave2ChatJoinTest extends TestCase
{
    // SELECT cm.id, cm.userid, cm.chatid, cm.date, cr.user1, cr.user2
    //   FROM chat_messages cm INNER JOIN chat_rooms cr ON cr.id = cm.chatid
    //   WHERE cm.date >= ? AND cm.replyexpected = 1 AND cm.replyreceived = 0
    //     AND cr.chattype = ?
    private const SITE_PENDING_EXPECTED = '1a2fdf6e9cab';

    // SELECT cm.id, cm.chatid, cm.date, cr.user1, cr.user2
    //   FROM chat_messages cm INNER JOIN chat_rooms cr ON cr.id = cm.chatid
    //   WHERE cm.userid = ? AND cm.date > ? AND cr.chattype = ? AND cm.type IN (?, ?)
    private const SITE_USER_MESSAGES = '3a4378f7f24e';

    // SELECT users_emails.userid AS emailid, users_donations.id AS donationid
    //   FROM users_donations INNER JOIN users_emails ON ... WHERE ...
    private const SITE_DONATION_USERS = '7fa41827ba83';

    public function test_pending_expected_replies(): void
    {
        GoldenSql::assert(self::SITE_PENDING_EXPECTED, fn () => DB::table('chat_messages as cm')
            ->select('cm.id', 'cm.userid', 'cm.chatid', 'cm.date', 'cr.user1', 'cr.user2')
            ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
            ->where('cm.date', '>=', '2026-01-01 00:00:00')
            ->where('cm.replyexpected', 1)
            ->where('cm.replyreceived', 0)
            ->where('cr.chattype', ChatRoom::TYPE_USER2USER));
    }

    public function test_user_messages_for_delay(): void
    {
        GoldenSql::assert(self::SITE_USER_MESSAGES, fn () => DB::table('chat_messages as cm')
            ->select('cm.id', 'cm.chatid', 'cm.date', 'cr.user1', 'cr.user2')
            ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
            ->where('cm.userid', 1)
            ->where('cm.date', '>', '2026-01-01 00:00:00')
            ->where('cr.chattype', ChatRoom::TYPE_USER2USER)
            ->whereIn('cm.type', [ChatMessage::TYPE_INTERESTED, ChatMessage::TYPE_DEFAULT]));
    }

    public function test_donations_missing_userid(): void
    {
        GoldenSql::assert(self::SITE_DONATION_USERS, fn () => DB::table('users_donations')
            ->select('users_emails.userid as emailid', 'users_donations.id as donationid')
            ->join('users_emails', 'users_emails.email', '=', 'users_donations.Payer')
            ->whereNull('users_donations.userid')
            ->where('users_donations.Payer', '!=', '')
            ->where('users_emails.email', '!=', ''));
    }
}
