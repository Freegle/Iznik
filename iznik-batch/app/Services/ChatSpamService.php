<?php

namespace App\Services;

use App\Mail\Chat\SpamWarningMail;
use App\Models\ChatMessage;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Processes chat spam:
 *   1. Finds chat rooms where one participant is a known spammer and warns the innocent party.
 *   2. Auto-marks pending chat messages from high-rejection-rate users as spam.
 *
 * Mirrors V1 cron/chat_spam.php.
 */
class ChatSpamService
{
    /** How many days back to look for recent spam messages. */
    private const SPAM_LOOKBACK_DAYS = 31;

    /** Minimum number of rejected messages before auto-marking. */
    private const AUTO_MARK_THRESHOLD = 5;

    /**
     * Warn innocent users who have been chatting with spammers.
     *
     * Finds User2User chat rooms active in the last 7 days where one user is a known
     * spammer (spam_users.collection='Spammer') and the room hasn't already been flagged.
     * Sends a warning email to the non-spam user.
     *
     * @return int Number of warning emails sent.
     */
    public function warnInnocentUsers(): int
    {
        $since = now()->subDays(7)->toDateTimeString();

        $rooms = DB::select(
            "SELECT chat_rooms.id, spam_users.userid AS spammer_id, user1, user2
             FROM chat_rooms
             INNER JOIN spam_users ON user1 = spam_users.userid
             WHERE latestmessage >= ? AND flaggedspam = 0 AND spam_users.collection = 'Spammer'
             UNION
             SELECT chat_rooms.id, spam_users.userid AS spammer_id, user1, user2
             FROM chat_rooms
             INNER JOIN spam_users ON user2 = spam_users.userid
             WHERE latestmessage >= ? AND flaggedspam = 0 AND spam_users.collection = 'Spammer'",
            [$since, $since]
        );

        $sent = 0;

        foreach ($rooms as $room) {
            // Make sure there are visible messages (not just held/rejected)
            $visibleCount = DB::table('chat_messages')
                ->where('chatid', $room->id)
                ->where('reviewrequired', 0)
                ->where('reviewrejected', 0)
                ->where('processingsuccessful', 1)
                ->count();

            if ($visibleCount === 0) {
                continue;
            }

            $innocentId = $room->user1 == $room->spammer_id ? $room->user2 : $room->user1;
            $innocent = User::find($innocentId);

            if (! $innocent || ! $innocent->email_preferred) {
                continue;
            }

            $spammer = User::find($room->spammer_id);
            $spammerName = $spammer ? ($spammer->displayname ?? $spammer->fullname ?? 'Unknown') : 'Unknown';

            // Find a subject and reply-to from any related message in this chat
            [$replyTo, $replyName, $subject] = $this->findReplyDetails($room->id, $innocent);

            try {
                $mail = new SpamWarningMail($innocent, $spammerName, $subject, $replyTo, $replyName);
                Mail::to($innocent->email_preferred)->send($mail);

                DB::update('UPDATE chat_rooms SET flaggedspam = 1 WHERE id = ?', [$room->id]);

                Log::info('Chat spam warning sent', [
                    'chat_id'    => $room->id,
                    'innocent'   => $innocentId,
                    'spammer'    => $room->spammer_id,
                ]);

                $sent++;
            } catch (\Throwable $e) {
                Log::error('Failed to send chat spam warning', [
                    'chat_id' => $room->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Auto-mark pending review messages from probable spammers as rejected.
     *
     * Users with >5 rejected chat messages in the last 31 days, who have never had a
     * message approved, and who are not moderators, are considered probable spammers.
     * Any of their messages still held for review are automatically rejected.
     *
     * @return int Number of messages auto-marked as spam.
     */
    public function autoMarkSpam(): int
    {
        $start = now()->subDays(self::SPAM_LOOKBACK_DAYS)->toDateString();

        $users = DB::select(
            "SELECT DISTINCT chat_messages.userid, COUNT(*) AS count
             FROM chat_messages
             LEFT JOIN spam_users ON spam_users.userid = chat_messages.userid
             INNER JOIN users ON users.id = chat_messages.userid
             WHERE chat_messages.date >= ?
               AND reviewrejected = 1
               AND (spam_users.collection IS NULL OR spam_users.collection != 'Whitelisted')
               AND users.systemrole = 'User'
             GROUP BY chat_messages.userid
             HAVING count > ?
             ORDER BY count DESC",
            [$start, self::AUTO_MARK_THRESHOLD]
        );

        $count = 0;

        foreach ($users as $user) {
            $user = (object) $user;

            $isModerator = DB::table('memberships')
                ->where('userid', $user->userid)
                ->whereIn('role', ['Moderator', 'Owner'])
                ->exists();

            if ($isModerator) {
                continue;
            }

            // Skip if any of their messages were ever approved (could be spoofed)
            $hasApproved = DB::table('chat_messages')
                ->where('userid', $user->userid)
                ->where('reviewrequired', 1)
                ->whereNotNull('reviewedby')
                ->where('reviewrejected', 0)
                ->exists();

            if ($hasApproved) {
                continue;
            }

            $pending = DB::table('chat_messages')
                ->where('userid', $user->userid)
                ->where('reviewrequired', 1)
                ->whereNull('reviewedby')
                ->count();

            if ($pending === 0) {
                continue;
            }

            DB::update(
                "UPDATE chat_messages
                 SET reviewrequired = 0, processingrequired = 0, processingsuccessful = 0,
                     reviewrejected = 1, reviewedby = NULL
                 WHERE userid = ? AND reviewrequired = 1 AND reviewedby IS NULL",
                [$user->userid]
            );

            Log::info('Auto-marked chat messages as spam', [
                'userid'  => $user->userid,
                'count'   => $pending,
                'rejects' => $user->count,
            ]);

            $count += $pending;
        }

        return $count;
    }

    /**
     * Find a suitable reply-to address and message subject for the spam warning email.
     *
     * Tries to find the group that the chat was about via refmsgids, falling back to the
     * innocent user's first group membership.
     *
     * @return array{0: string, 1: string, 2: string|null} [replyTo, replyName, subject]
     */
    private function findReplyDetails(int $chatId, User $innocent): array
    {
        $support = config('freegle.mail.support', 'support@ilovefreegle.org');
        $siteName = config('freegle.branding.name', 'Freegle');
        $groupDomain = config('freegle.mail.group_domain', 'groups.ilovefreegle.org');

        $replyTo = $support;
        $replyName = $siteName;
        $subject = null;

        // Look for a related message in this chat via refmsgid
        $refMsg = DB::table('chat_messages')
            ->where('chatid', $chatId)
            ->whereNotNull('refmsgid')
            ->orderByDesc('id')
            ->value('refmsgid');

        if ($refMsg) {
            $msg = Message::find($refMsg);
            if ($msg) {
                $subject = $msg->subject;
                $groupId = MessageGroup::where('msgid', $msg->id)
                    ->orderByDesc('id')
                    ->value('groupid');

                if ($groupId) {
                    $group = Group::find($groupId);
                    if ($group) {
                        $replyTo = $group->nameshort.'-volunteers@'.$groupDomain;
                        $replyName = ($group->namefull ?? $group->nameshort ?? $siteName).' Volunteers';
                    }
                }
            }
        }

        if ($replyTo === $support) {
            // Fall back to first group the innocent user is a member of
            $groupId = DB::table('memberships')
                ->where('userid', $innocent->id)
                ->value('groupid');

            if ($groupId) {
                $group = Group::find($groupId);
                if ($group) {
                    $replyTo = $group->nameshort.'-volunteers@'.$groupDomain;
                    $replyName = ($group->namefull ?? $group->nameshort ?? $siteName).' Volunteers';
                }
            }
        }

        return [$replyTo, $replyName, $subject];
    }
}
