<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: app/Services/SpamCleanupService.php.
 */
class Wave2SpamCleanupTest extends TestCase
{
    private const SPAMMER_COLLECTION = 'Spammer';
    private const MEMBER_ROLE = 'Member';

    // SELECT memberships.userid, memberships.groupid FROM memberships
    //   INNER JOIN spam_users ... WHERE spam_users.collection = ? AND memberships.role = ?
    private const SITE_SPAM_MEMBERSHIPS = '2f303c41d476';

    // SELECT DISTINCT messages.id, messages_groups.groupid FROM messages INNER JOIN ... x3
    private const SITE_SPAM_MESSAGES = 'f464bbe429f8';

    // DELETE FROM <t> WHERE <col> IN (SELECT userid FROM spam_users WHERE collection = ?)
    private const SITE_DEL_NEWSFEED = '0dff831da965';
    private const SITE_DEL_NOTIFICATIONS = 'a1058358a274';
    private const SITE_DEL_EXPECTED = '82d31a13ca56';
    private const SITE_DEL_SESSIONS = 'd8432452343e';

    public function test_spam_memberships(): void
    {
        GoldenSql::assert(self::SITE_SPAM_MEMBERSHIPS, fn () => DB::table('memberships')
            ->select('memberships.userid', 'memberships.groupid')
            ->join('spam_users', 'memberships.userid', '=', 'spam_users.userid')
            ->where('spam_users.collection', self::SPAMMER_COLLECTION)
            ->where('memberships.role', self::MEMBER_ROLE));
    }

    /**
     * Two predicates live in ON clauses rather than the WHERE, and they are
     * kept there. The users one is a GUARD, not a filter: systemrole = 'User'
     * is what stops this deleting posts by moderators or support staff who
     * happen to be flagged as spammers. Moving it, or losing it, turns a
     * cleanup job into an incident.
     */
    public function test_spam_messages(): void
    {
        GoldenSql::assert(self::SITE_SPAM_MESSAGES, fn () => DB::table('messages')
            ->distinct()
            ->select('messages.id', 'messages_groups.groupid')
            ->join('spam_users', function ($j) {
                $j->on('messages.fromuser', '=', 'spam_users.userid')
                  ->where('spam_users.collection', self::SPAMMER_COLLECTION);
            })
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->join('users', function ($j) {
                $j->on('messages.fromuser', '=', 'users.id')
                  ->where('users.systemrole', 'User');
            })
            ->whereNull('messages.deleted'));
    }

    /**
     * Four deletes sharing one shape. whereIn with a CLOSURE renders the same
     * correlated IN (SELECT ...) the raw statements had; fetching the spammer
     * ids first and passing an array would be a different statement and a race,
     * since the spam list can change between the two queries.
     */
    public function test_spam_cascade_deletes(): void
    {
        $sub = fn ($q) => $q->from('spam_users')
            ->select('userid')
            ->where('collection', self::SPAMMER_COLLECTION);

        GoldenSql::assertDelete(self::SITE_DEL_NEWSFEED, fn () => DB::table('newsfeed')
            ->whereIn('userid', $sub));

        GoldenSql::assertDelete(self::SITE_DEL_NOTIFICATIONS, fn () => DB::table('users_notifications')
            ->whereIn('fromuser', $sub));

        GoldenSql::assertDelete(self::SITE_DEL_EXPECTED, fn () => DB::table('users_expected')
            ->whereIn('expecter', $sub));

        // The sessions one carries an extra IS NOT NULL. Redundant against the
        // IN, but the raw statement had it, so it is preserved rather than
        // tidied away.
        GoldenSql::assertDelete(self::SITE_DEL_SESSIONS, fn () => DB::table('sessions')
            ->whereIn('userid', $sub)
            ->whereNotNull('userid'));
    }
}
