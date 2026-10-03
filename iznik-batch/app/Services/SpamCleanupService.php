<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bans spam members and removes their content, in a Freegle with no groups.
 *
 * Mirrors the legacy V1 PHP Spam::removeSpamMembers(), rewritten for the single
 * global user (memberships/messages_groups/groups/users_banned dropped by
 * 2026_09_20_000001_remove_group_model.php - ai-judgement.md).
 *
 * Actions taken for each known spammer (spam_users.collection = 'Spammer'):
 *   1. The user is banned globally (users.banned/bannedby), once, if not already.
 *   2. Messages they authored, not yet deleted, are soft-deleted.
 *   3. Chat messages they sent are rejected (reviewrejected=1, reviewrequired=0).
 *   4. Newsfeed posts are deleted.
 *   5. Site notifications sent from them are deleted.
 *   6. "Waiting for reply" (users_expected) records where they are the expecter are deleted.
 *   7. Active sessions are deleted.
 *
 * Returns the number of newly-banned users + deleted messages (matching V1 return value).
 */
class SpamCleanupService
{
    private const SPAMMER_COLLECTION = 'Spammer';

    /**
     * Ban spammers globally and clean up their content.
     *
     * Returns the full stats array; see CheckSpammersCommand for how each key is
     * reported. 'banned' used to be 'memberships' (V1 counted memberships removed,
     * one per group) - there is one Freegle now, so it counts users newly banned.
     */
    public function removeSpamMembers(bool $dryRun = false): array
    {
        $stats = [
            'banned'        => $this->removeSpamMemberships($dryRun),
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
     * Ban known spammers globally and log the action. Used to remove the member-role
     * membership from each group and users_banned that row per group (V1's first loop
     * in removeSpamMembers()); memberships, messages_groups and users_banned were
     * dropped by 2026_09_20_000001_remove_group_model.php in favour of a single
     * users.banned/bannedby pair (ai-judgement.md) - there is one Freegle now, so a
     * spammer is banned once rather than evicted group by group. Matches the log
     * shape iznik-server-go/member/member.go's BanMember/UnbanMember already write
     * (type=User, subtype=Banned) - see the open enum-extension flag to whoever owns
     * the logs migration, this value has no logs.subtype ENUM member yet.
     */
    public function removeSpamMemberships(bool $dryRun = false): int
    {
        $spammers = DB::table('spam_users')
            ->join('users', 'users.id', '=', 'spam_users.userid')
            ->where('spam_users.collection', self::SPAMMER_COLLECTION)
            ->whereNull('users.banned')
            ->pluck('spam_users.userid');

        if ($dryRun) {
            return $spammers->count();
        }

        foreach ($spammers as $userId) {
            Log::info('Banning spam member', [
                'userid' => $userId,
            ]);

            DB::table('users')
                ->where('id', $userId)
                ->update(['banned' => now()]);

            DB::table('logs')->insert([
                'user' => $userId,
                'type' => 'User',
                'subtype' => 'Banned',
                'text' => 'Autoremoved spammer',
                'timestamp' => now(),
            ]);
        }

        return $spammers->count();
    }

    /**
     * Soft-delete messages authored by known spammers. Mirrors the second loop in
     * V1 removeSpamMembers(), rewritten now a message has one collection/deleted
     * state directly on `messages` rather than one row per group in the dropped
     * messages_groups (ai-judgement.md) - no per-group tracking to fan out over,
     * so this is a plain soft-delete by id.
     */
    public function deleteSpamMessages(bool $dryRun = false): int
    {
        $msgIds = DB::table('messages')
            ->join('spam_users', function ($join) {
                $join->on('messages.fromuser', '=', 'spam_users.userid')
                    ->where('spam_users.collection', self::SPAMMER_COLLECTION);
            })
            ->join('users', 'messages.fromuser', '=', 'users.id')
            ->where('users.systemrole', 'User')
            ->whereNull('messages.deleted')
            ->pluck('messages.id')
            ->unique();

        if ($dryRun) {
            return $msgIds->count();
        }

        foreach ($msgIds as $msgId) {
            Log::info('Deleting spam message', [
                'msgid' => $msgId,
            ]);

            DB::table('messages')
                ->where('id', $msgId)
                ->whereNull('deleted')
                ->update(['deleted' => now()]);
        }

        return $msgIds->count();
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
            $updated += DB::update(
                'UPDATE chat_messages SET reviewrejected = 1, reviewrequired = 0 WHERE id = ?',
                [$row->id],
            );
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
        return (int) DB::delete(
            "DELETE FROM newsfeed
             WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?)",
            [self::SPAMMER_COLLECTION]
        );
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
        return (int) DB::delete(
            "DELETE FROM users_notifications
             WHERE fromuser IN (SELECT userid FROM spam_users WHERE collection = ?)",
            [self::SPAMMER_COLLECTION]
        );
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
        return (int) DB::delete(
            "DELETE FROM users_expected
             WHERE expecter IN (SELECT userid FROM spam_users WHERE collection = ?)",
            [self::SPAMMER_COLLECTION]
        );
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
        return (int) DB::delete(
            "DELETE FROM sessions
             WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?)
               AND userid IS NOT NULL",
            [self::SPAMMER_COLLECTION]
        );
    }
}
