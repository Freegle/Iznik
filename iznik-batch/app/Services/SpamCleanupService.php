<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes spam members and their content from Freegle groups.
 *
 * Mirrors the legacy V1 PHP Spam::removeSpamMembers().
 *
 * Actions taken for each known spammer (spam_users.collection = 'Spammer'):
 *   1. Member-role memberships are removed and the user is banned from those groups.
 *   2. Messages they authored (on any group, not yet deleted) are soft-deleted.
 *   3. Chat messages they sent are rejected (reviewrejected=1, reviewrequired=0).
 *   4. Newsfeed posts are deleted.
 *   5. Site notifications sent from them are deleted.
 *   6. "Waiting for reply" (users_expected) records where they are the expecter are deleted.
 *   7. Active sessions are deleted.
 *
 * Returns the number of removed memberships + deleted messages (matching V1 return value).
 */
class SpamCleanupService
{
    private const SPAMMER_COLLECTION = 'Spammer';

    private const MEMBER_ROLE = 'Member';

    /**
     * Remove spammers from groups and clean up their content.
     *
     * Returns the same value as before (memberships + messages count) for
     * backwards compat with the existing command, plus the full stats array
     * is available via the second return position for the dry-run path.
     */
    public function removeSpamMembers(bool $dryRun = false): array
    {
        $stats = [
            'memberships'   => $this->removeSpamMemberships($dryRun),
            'messages'      => $this->deleteSpamMessages($dryRun),
            'chat_messages' => $this->rejectSpamChatMessages($dryRun),
            'newsfeed'      => $this->deleteSpamNewsfeedItems($dryRun),
            'notifications' => $this->deleteSpamNotifications($dryRun),
            'expected'      => $this->deleteSpamExpectedRecords($dryRun),
            'sessions'      => $this->deleteSpamSessions($dryRun),
        ];

        return $stats;
    }

    /**
     * Find member-role memberships for known spammers, ban them, remove the membership,
     * and log the action. Mirrors the first loop in V1 removeSpamMembers().
     */
    public function removeSpamMemberships(bool $dryRun = false): int
    {
        $spammers = DB::table('memberships')
            ->select('memberships.userid', 'memberships.groupid')
            ->join('spam_users', 'memberships.userid', '=', 'spam_users.userid')
            ->where('spam_users.collection', self::SPAMMER_COLLECTION)
            ->where('memberships.role', self::MEMBER_ROLE)
            ->get()
            ->all();

        if ($dryRun) {
            return count($spammers);
        }

        foreach ($spammers as $spammer) {
            Log::info('Removing spam member', [
                'userid' => $spammer->userid,
                'groupid' => $spammer->groupid,
            ]);

            DB::table('users_banned')->insertOrIgnore([
                'userid' => $spammer->userid,
                'groupid' => $spammer->groupid,
                'byuser' => null,
            ]);

            DB::table('memberships')
                ->where('userid', $spammer->userid)
                ->where('groupid', $spammer->groupid)
                ->delete();

            DB::table('logs')->insert([
                'user' => $spammer->userid,
                'type' => 'Group',
                'subtype' => 'Left',
                'groupid' => $spammer->groupid,
                'text' => 'Autoremoved spammer',
                'timestamp' => now(),
            ]);
        }

        return count($spammers);
    }

    /**
     * Soft-delete messages authored by known spammers that are still on groups.
     * Mirrors the second loop in V1 removeSpamMembers().
     */
    public function deleteSpamMessages(bool $dryRun = false): int
    {
        $msgs = DB::table('messages')
            ->distinct()
            ->select('messages.id', 'messages_groups.groupid')
            // Both extra predicates stay in their ON clauses, where the raw
            // statement put them, rather than migrating to the WHERE.
            ->join('spam_users', function ($j) {
                $j->on('messages.fromuser', '=', 'spam_users.userid')
                  ->where('spam_users.collection', self::SPAMMER_COLLECTION);
            })
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            // users.systemrole = 'User' is a GUARD: it keeps this from deleting
            // posts by moderators or support staff who happen to be flagged.
            ->join('users', function ($j) {
                $j->on('messages.fromuser', '=', 'users.id')
                  ->where('users.systemrole', 'User');
            })
            ->whereNull('messages.deleted')
            ->get()
            ->all();

        if ($dryRun) {
            return count($msgs);
        }

        foreach ($msgs as $msg) {
            Log::info('Deleting spam message', [
                'msgid' => $msg->id,
                'groupid' => $msg->groupid,
            ]);

            DB::table('messages_groups')
                ->where('msgid', $msg->id)
                ->update(['deleted' => 1]);
        }

        // Mark messages as deleted if all their group entries are deleted.
        if (!empty($msgs)) {
            $msgIds = array_unique(array_column($msgs, 'id'));
            foreach ($msgIds as $msgId) {
                // useWritePdo: this count gates the parent-message soft-delete below,
                // and it reads the rows we just UPDATEd to deleted=1 above. Under the
                // read/write split a plain read could hit a lagging replica that still
                // shows those rows as deleted=0, leaving the spam message live.
                $remainingGroups = DB::table('messages_groups')
                    ->useWritePdo()
                    ->where('msgid', $msgId)
                    ->where('deleted', 0)
                    ->count();

                if ($remainingGroups === 0) {
                    DB::table('messages')
                        ->where('id', $msgId)
                        ->whereNull('deleted')
                        ->update(['deleted' => now()]);
                }
            }
        }

        return count($msgs);
    }

    /**
     * Reject chat messages from known spammers.
     */
    public function rejectSpamChatMessages(bool $dryRun = false): int
    {
        $idsQuery = DB::table('chat_messages')
            ->whereIn('userid', function ($q) {
                $q->select('userid')->from('spam_users')->where('collection', self::SPAMMER_COLLECTION);
            })
            ->where('reviewrejected', '!=', 1);

        if ($dryRun) {
            return (int) $idsQuery->count();
        }

        // Per-PK update — the original consolidated
        // `UPDATE chat_messages … WHERE userid IN (subquery)` locked every
        // matching row at once and could deadlock against the per-message
        // UPDATEs in the chat notification pipeline (same class of failure
        // we hit at 02:08 UTC 15 May in ChatExpectedService). Updating by
        // single id keeps each statement's lock window to milliseconds.
        // Stream ids in keyset-paginated chunks rather than pluck()-ing the whole
        // spammer chat_messages backlog into memory. Setting reviewrejected only
        // moves rows out of the filter behind the cursor, so lazyById is safe here.
        $updated = 0;
        foreach ($idsQuery->lazyById(1000) as $row) {
            $updated += DB::table('chat_messages')
                ->where('id', $row->id)
                ->update(['reviewrejected' => 1, 'reviewrequired' => 0]);
        }

        return $updated;
    }

    /**
     * Delete newsfeed items created by known spammers.
     */
    public function deleteSpamNewsfeedItems(bool $dryRun = false): int
    {
        if ($dryRun) {
            return (int) DB::table('newsfeed')
                ->whereIn('userid', function ($q) {
                    $q->select('userid')->from('spam_users')->where('collection', self::SPAMMER_COLLECTION);
                })
                ->count();
        }
        return DB::table('newsfeed')
            // whereIn with a closure renders the same correlated
            // IN (SELECT ...) the raw statement had. Fetching the ids
            // first and passing an array would be a different
            // statement - and a race, since the spam list can change
            // between the two queries.
            ->whereIn('userid', fn ($q) => $q->from('spam_users')
                ->select('userid')
                ->where('collection', self::SPAMMER_COLLECTION))
            ->delete();
    }

    /**
     * Delete site notifications sent from known spammers.
     */
    public function deleteSpamNotifications(bool $dryRun = false): int
    {
        if ($dryRun) {
            return (int) DB::table('users_notifications')
                ->whereIn('fromuser', function ($q) {
                    $q->select('userid')->from('spam_users')->where('collection', self::SPAMMER_COLLECTION);
                })
                ->count();
        }
        return DB::table('users_notifications')
            // whereIn with a closure renders the same correlated
            // IN (SELECT ...) the raw statement had. Fetching the ids
            // first and passing an array would be a different
            // statement - and a race, since the spam list can change
            // between the two queries.
            ->whereIn('fromuser', fn ($q) => $q->from('spam_users')
                ->select('userid')
                ->where('collection', self::SPAMMER_COLLECTION))
            ->delete();
    }

    /**
     * Delete "waiting for reply" records where the spammer is the expecter.
     */
    public function deleteSpamExpectedRecords(bool $dryRun = false): int
    {
        if ($dryRun) {
            return (int) DB::table('users_expected')
                ->whereIn('expecter', function ($q) {
                    $q->select('userid')->from('spam_users')->where('collection', self::SPAMMER_COLLECTION);
                })
                ->count();
        }
        return DB::table('users_expected')
            // whereIn with a closure renders the same correlated
            // IN (SELECT ...) the raw statement had. Fetching the ids
            // first and passing an array would be a different
            // statement - and a race, since the spam list can change
            // between the two queries.
            ->whereIn('expecter', fn ($q) => $q->from('spam_users')
                ->select('userid')
                ->where('collection', self::SPAMMER_COLLECTION))
            ->delete();
    }

    /**
     * Delete active sessions for known spammers.
     */
    public function deleteSpamSessions(bool $dryRun = false): int
    {
        if ($dryRun) {
            return (int) DB::table('sessions')
                ->whereIn('userid', function ($q) {
                    $q->select('userid')->from('spam_users')->where('collection', self::SPAMMER_COLLECTION);
                })
                ->whereNotNull('userid')
                ->count();
        }
        return DB::table('sessions')
            ->whereIn('userid', fn ($q) => $q->from('spam_users')
                ->select('userid')
                ->where('collection', self::SPAMMER_COLLECTION))
            // Redundant against the IN, but kept: the raw statement had it and
            // this converts behaviour, not style.
            ->whereNotNull('userid')
            ->delete();
    }
}
