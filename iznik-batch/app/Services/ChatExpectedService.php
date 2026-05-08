<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Support\Facades\DB;

/**
 * Updates chat reply-expectation tracking and per-user reply-time metrics.
 *
 * Mirrors V1 cron/chat_expected.php:
 *   1. Clears replyexpected=1 for deleted users (shouldn't count as unanswered).
 *   2. Clears replyexpected=1 for known spammers (same reason).
 *   3. Updates users_expected and replyreceived based on whether a reply arrived.
 *   4. Recalculates median reply times for affected users and stores in users_replytime.
 */
class ChatExpectedService
{
    /** Hours after which a User2User message is considered overdue for reply. */
    private const EXPECTED_GRACE_MINUTES = 24 * 60;

    /** Days back to look when evaluating outstanding expected replies. */
    private const LOOKBACK_DAYS = 31;

    /** Days back to look when computing reply-time statistics. */
    private const REPLY_TIME_LOOKBACK_DAYS = 90;

    /**
     * Orchestrates the full expected-reply update cycle.
     * Mirrors V1 cron/chat_expected.php top-level flow.
     *
     * @return array{deleted_cleared: int, spam_cleared: int, waiting: int, received: int}
     */
    public function updateChatExpected(): array
    {
        $deleted = $this->tidyDeletedUsersReplies();
        $spam = $this->tidySpamUsersReplies();
        $stats = $this->updateExpected();

        return array_merge(['deleted_cleared' => $deleted, 'spam_cleared' => $spam], $stats);
    }

    /**
     * Clear replyexpected on chat messages from recently-deleted users.
     * Mirrors V1 chat_expected.php tidy-deleted loop.
     *
     * @return int Number of messages cleared.
     */
    public function tidyDeletedUsersReplies(): int
    {
        $since = now()->subDay()->toDateTimeString();

        return DB::update(
            "UPDATE chat_messages
             SET replyexpected = 0
             WHERE replyexpected = 1
               AND replyreceived = 0
               AND userid IN (
                   SELECT id FROM users WHERE deleted IS NOT NULL AND deleted >= ?
               )",
            [$since]
        );
    }

    /**
     * Clear replyexpected on chat messages from known spammers.
     * Mirrors V1 chat_expected.php tidy-spam loop.
     *
     * @return int Number of messages cleared.
     */
    public function tidySpamUsersReplies(): int
    {
        return DB::update(
            "UPDATE chat_messages
             SET replyexpected = 0
             WHERE replyexpected = 1
               AND replyreceived = 0
               AND userid IN (
                   SELECT userid FROM spam_users WHERE collection = 'Spammer'
               )"
        );
    }

    /**
     * Update users_expected based on whether replies have been received.
     *
     * For each User2User chat message with replyexpected=1, replyreceived=0:
     *   - If a later message from the other user exists: mark received, set value=1.
     *   - Otherwise: set value=-1 (still waiting).
     *
     * Mirrors V1 ChatRoom::updateExpected().
     *
     * @return array{waiting: int, received: int}
     */
    public function updateExpected(): array
    {
        $oldest = now()->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateTimeString();

        $pending = DB::select(
            "SELECT cm.id, cm.userid, cm.chatid, cm.date,
                    cr.user1, cr.user2
             FROM chat_messages cm
             INNER JOIN chat_rooms cr ON cr.id = cm.chatid
             WHERE cm.date >= ?
               AND cm.replyexpected = 1
               AND cm.replyreceived = 0
               AND cr.chattype = ?",
            [$oldest, ChatRoom::TYPE_USER2USER]
        );

        $waiting = 0;
        $received = 0;

        foreach ($pending as $msg) {
            $other = $msg->userid == $msg->user1 ? $msg->user2 : $msg->user1;

            $replyCount = DB::table('chat_messages')
                ->where('chatid', $msg->chatid)
                ->where('id', '>', $msg->id)
                ->where('userid', $other)
                ->count();

            if ($replyCount > 0) {
                DB::update('UPDATE chat_messages SET replyreceived = 1 WHERE id = ?', [$msg->id]);

                DB::statement(
                    'INSERT IGNORE INTO users_expected (expecter, expectee, chatmsgid, value)
                     VALUES (?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE value = 1',
                    [$msg->userid, $other, $msg->id]
                );

                $received++;
            } else {
                DB::statement(
                    'INSERT IGNORE INTO users_expected (expecter, expectee, chatmsgid, value)
                     VALUES (?, ?, ?, -1)
                     ON DUPLICATE KEY UPDATE value = -1',
                    [$msg->userid, $other, $msg->id]
                );

                $waiting++;
            }
        }

        return compact('waiting', 'received');
    }

    /**
     * Recalculate median reply times for the given user IDs and update users_replytime.
     *
     * Mirrors V1 ChatRoom::replyTimes($uids, $force = TRUE).
     *
     * Algorithm per user:
     *   - Fetch their User2User messages (Default/Interested) from last 90 days.
     *   - For each message they sent, compute reply delay:
     *       a. If the other user hasn't replied yet: delay = now − their send time.
     *       b. If the other user did reply: delay = other_reply_time − this_message_time.
     *   - Calculate the median of all delays (ignoring > 30-day gaps).
     *   - Store in users_replytime.
     *
     * @param  int[]  $userIds
     */
    public function updateReplyTimes(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $since = now()->subDays(self::REPLY_TIME_LOOKBACK_DAYS)->toDateTimeString();
        $maxDelay = 30 * 24 * 3600;

        foreach ($userIds as $userId) {
            $msgs = DB::select(
                "SELECT cm.id, cm.chatid, cm.date, cr.user1, cr.user2
                 FROM chat_messages cm
                 INNER JOIN chat_rooms cr ON cr.id = cm.chatid
                 WHERE cm.userid = ?
                   AND cm.date > ?
                   AND cr.chattype = ?
                   AND cm.type IN (?, ?)",
                [
                    $userId,
                    $since,
                    ChatRoom::TYPE_USER2USER,
                    ChatMessage::TYPE_INTERESTED,
                    ChatMessage::TYPE_DEFAULT,
                ]
            );

            $delays = [];

            foreach ($msgs as $msg) {
                $other = $msg->user1 == $userId ? $msg->user2 : $msg->user1;

                // Check if the other user has an outstanding un-replied message
                $outstanding = DB::select(
                    "SELECT cm.date FROM chat_messages cm
                     WHERE cm.chatid = ?
                       AND cm.userid = ?
                       AND cm.id > ?
                     ORDER BY cm.id DESC
                     LIMIT 1",
                    [$msg->chatid, $other, $msg->id]
                );

                if (!empty($outstanding)) {
                    // Other user replied — compute how long it took
                    $delay = strtotime($outstanding[0]->date) - strtotime($msg->date);
                    if ($delay > 0 && $delay < $maxDelay) {
                        $delays[] = $delay;
                    }
                } else {
                    // No reply from the other user: find if we're waiting on them
                    $lastOther = DB::select(
                        "SELECT MAX(date) AS max FROM chat_messages
                         WHERE chatid = ? AND id < ? AND userid = ?",
                        [$msg->chatid, $msg->id, $other]
                    );

                    if (!empty($lastOther) && $lastOther[0]->max) {
                        $delay = strtotime($msg->date) - strtotime($lastOther[0]->max);
                        if ($delay > 0 && $delay < $maxDelay) {
                            $delays[] = $delay;
                        }
                    }
                }
            }

            $delays = array_values(array_unique($delays));
            $replyTime = empty($delays) ? null : $this->calculateMedian($delays);

            DB::table('users_replytime')->updateOrInsert(
                ['userid' => $userId],
                ['replytime' => $replyTime]
            );
        }
    }

    /**
     * Calculate the median of a non-empty sorted integer array.
     */
    private function calculateMedian(array $values): int
    {
        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return (int) (($values[$mid - 1] + $values[$mid]) / 2);
        }

        return $values[$mid];
    }
}
