<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes spam members and their content from Freegle groups.
 *
 * Mirrors V1 Spam::removeSpamMembers() from iznik-server/include/spam/Spam.php.
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
     */
    public function removeSpamMembers(): int
    {
        $count = 0;
        $count += $this->removeSpamMemberships();
        $count += $this->deleteSpamMessages();
        $this->rejectSpamChatMessages();
        $this->deleteSpamNewsfeedItems();
        $this->deleteSpamNotifications();
        $this->deleteSpamExpectedRecords();
        $this->deleteSpamSessions();

        return $count;
    }

    /**
     * Find member-role memberships for known spammers, ban them, remove the membership,
     * and log the action. Mirrors the first loop in V1 removeSpamMembers().
     */
    public function removeSpamMemberships(): int
    {
        $spammers = DB::select(
            "SELECT memberships.userid, memberships.groupid
             FROM memberships
             INNER JOIN spam_users ON memberships.userid = spam_users.userid
             WHERE spam_users.collection = ?
               AND memberships.role = ?",
            [self::SPAMMER_COLLECTION, self::MEMBER_ROLE]
        );

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
    public function deleteSpamMessages(): int
    {
        $msgs = DB::select(
            "SELECT DISTINCT messages.id, messages_groups.groupid
             FROM messages
             INNER JOIN spam_users ON messages.fromuser = spam_users.userid
               AND spam_users.collection = ?
             INNER JOIN messages_groups ON messages.id = messages_groups.msgid
             INNER JOIN users ON messages.fromuser = users.id
               AND users.systemrole = 'User'
             WHERE messages.deleted IS NULL",
            [self::SPAMMER_COLLECTION]
        );

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
                $remainingGroups = DB::table('messages_groups')
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
    public function rejectSpamChatMessages(): void
    {
        DB::update(
            "UPDATE chat_messages
             SET reviewrejected = 1, reviewrequired = 0
             WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?)
               AND reviewrejected != 1",
            [self::SPAMMER_COLLECTION]
        );
    }

    /**
     * Delete newsfeed items created by known spammers.
     */
    public function deleteSpamNewsfeedItems(): void
    {
        DB::delete(
            "DELETE FROM newsfeed
             WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?)",
            [self::SPAMMER_COLLECTION]
        );
    }

    /**
     * Delete site notifications sent from known spammers.
     */
    public function deleteSpamNotifications(): void
    {
        DB::delete(
            "DELETE FROM users_notifications
             WHERE fromuser IN (SELECT userid FROM spam_users WHERE collection = ?)",
            [self::SPAMMER_COLLECTION]
        );
    }

    /**
     * Delete "waiting for reply" records where the spammer is the expecter.
     */
    public function deleteSpamExpectedRecords(): void
    {
        DB::delete(
            "DELETE FROM users_expected
             WHERE expecter IN (SELECT userid FROM spam_users WHERE collection = ?)",
            [self::SPAMMER_COLLECTION]
        );
    }

    /**
     * Delete active sessions for known spammers.
     */
    public function deleteSpamSessions(): void
    {
        DB::delete(
            "DELETE FROM sessions
             WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?)
               AND userid IS NOT NULL",
            [self::SPAMMER_COLLECTION]
        );
    }
}
