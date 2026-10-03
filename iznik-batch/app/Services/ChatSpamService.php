<?php

namespace App\Services;

use App\Mail\Chat\SpamWarningMail;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function warnInnocentUsers(bool $dryRun = false): int
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
            [$replyTo, $replyName, $subject] = $this->findReplyDetails($room->id);

            try {
                if (! $dryRun) {
                    $mail = new SpamWarningMail($innocent, $spammerName, $subject, $replyTo, $replyName);
                    app(\App\Services\EmailSpoolerService::class)->spool($mail, $innocent->email_preferred);

                    DB::update('UPDATE chat_rooms SET flaggedspam = 1 WHERE id = ?', [$room->id]);

                    Log::info('Chat spam warning sent', [
                        'chat_id' => $room->id,
                        'innocent' => $innocentId,
                        'spammer' => $room->spammer_id,
                    ]);
                }

                $sent++;
            } catch (\Throwable $e) {
                Log::error('Failed to send chat spam warning', [
                    'chat_id' => $room->id,
                    'error' => $e->getMessage(),
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
    public function autoMarkSpam(bool $dryRun = false): int
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

            $isModerator = User::whereIn('systemrole', [
                User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN,
            ])->where('id', $user->userid)->exists();

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

            if (! $dryRun) {
                DB::update(
                    'UPDATE chat_messages
                     SET reviewrequired = 0, processingrequired = 0, processingsuccessful = 0,
                         reviewrejected = 1, reviewedby = NULL
                     WHERE userid = ? AND reviewrequired = 1 AND reviewedby IS NULL',
                    [$user->userid]
                );

                Log::info('Auto-marked chat messages as spam', [
                    'userid' => $user->userid,
                    'count' => $pending,
                    'rejects' => $user->count,
                ]);
            }

            $count += $pending;
        }

        return $count;
    }

    /**
     * Find a reply-to address, display name and subject for the spam warning email.
     *
     * There is one national site, so the reply-to is always the support address; this
     * just also tries to find the item the chat was about, so the warning can quote it.
     *
     * @return array{0: string, 1: string, 2: string|null} [replyTo, replyName, subject]
     */
    private function findReplyDetails(int $chatId): array
    {
        $replyTo = config('freegle.mail.support_addr', 'support@ilovefreegle.org');
        $replyName = config('freegle.branding.name', 'Freegle');
        $subject = null;

        // Look for a related message in this chat via refmsgid, purely so the warning
        // email can quote the item it was about.
        $refMsg = DB::table('chat_messages')
            ->where('chatid', $chatId)
            ->whereNotNull('refmsgid')
            ->orderByDesc('id')
            ->value('refmsgid');

        if ($refMsg) {
            $subject = Message::find($refMsg)?->subject;
        }

        return [$replyTo, $replyName, $subject];
    }
}
