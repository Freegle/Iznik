<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Carbon;
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

    /** Where the incremental run remembers how far it has read. */
    private const CURSOR_KEY = 'chat.expected_cursor';

    /**
     * How far back before the last run's start time to look for released replies. The job
     * runs every five minutes, so this is generous room for a long run or a clock that has
     * been nudged, at the cost of re-checking a few chats.
     */
    private const RELEASE_OVERLAP_MINUTES = 15;

    /**
     * Orchestrates the full expected-reply update cycle.
     * Mirrors V1 cron/chat_expected.php top-level flow.
     *
     * @return array{deleted_cleared: int, spam_cleared: int, waiting: int, received: int, checked: int, full: bool}
     */
    public function updateChatExpected(bool $dryRun = false, bool $full = false): array
    {
        $deleted = $this->tidyDeletedUsersReplies($dryRun);
        $spam = $this->tidySpamUsersReplies($dryRun);
        $stats = $this->updateExpected($dryRun, $full);

        return array_merge(['deleted_cleared' => $deleted, 'spam_cleared' => $spam], $stats);
    }

    /**
     * Clear replyexpected on chat messages from recently-deleted users.
     * Mirrors V1 chat_expected.php tidy-deleted loop.
     *
     * @return int Number of messages cleared.
     */
    public function tidyDeletedUsersReplies(bool $dryRun = false): int
    {
        $since = now()->subDay()->toDateTimeString();

        $idsQuery = DB::table('chat_messages')
            ->where('replyexpected', 1)
            ->where('replyreceived', 0)
            ->whereIn('userid', function ($q) use ($since) {
                $q->select('id')->from('users')->whereNotNull('deleted')->where('deleted', '>=', $since);
            });

        if ($dryRun) {
            return $idsQuery->count();
        }

        // V2 originally collapsed this into one big `UPDATE … WHERE userid IN
        // (SELECT …)`. That locks every chat_messages row matching the
        // subquery at once and deadlocked against the per-message UPDATEs
        // that mail:chat:user2user runs every minute (caught in production
        // 02:08 UTC, retry-after-3 gave up). Match V1 chat_expected.php
        // instead: fetch the ids, then UPDATE one row at a time by PK so
        // each statement holds a single-row lock for milliseconds.
        $updated = 0;
        foreach ($idsQuery->pluck('id') as $id) {
            $updated += DB::table('chat_messages')
                ->where('id', $id)
                ->update(['replyexpected' => 0]);
        }

        return $updated;
    }

    /**
     * Clear replyexpected on chat messages from known spammers.
     * Mirrors V1 chat_expected.php tidy-spam loop.
     *
     * @return int Number of messages cleared.
     */
    public function tidySpamUsersReplies(bool $dryRun = false): int
    {
        $idsQuery = DB::table('chat_messages')
            ->where('replyexpected', 1)
            ->where('replyreceived', 0)
            ->whereIn('userid', function ($q) {
                $q->select('userid')->from('spam_users')->where('collection', 'Spammer');
            });

        if ($dryRun) {
            return $idsQuery->count();
        }

        // Same per-PK pattern as tidyDeletedUsersReplies — avoids the
        // wide-range lock that previously deadlocked against concurrent
        // chat_messages writers.
        $updated = 0;
        foreach ($idsQuery->pluck('id') as $id) {
            $updated += DB::table('chat_messages')
                ->where('id', $id)
                ->update(['replyexpected' => 0]);
        }

        return $updated;
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
     * Every five minutes this used to re-ask the same question about all ~1,925 messages
     * still waiting for a reply - a chat_messages count per message - and then write the
     * same value=-1 row back for every one of them that was still waiting, which is where
     * the ~550k no-op writes a day came from. Both are avoidable:
     *
     *   - A waiting message can only stop waiting when a reply becomes deliverable, so only
     *     chats that have had something happen need re-checking.
     *   - A row whose value is already what we are about to write does not need writing.
     *
     * "Something happened" means a new chat message, OR a rippling-held reply being
     * released: a held reply is already in chat_messages but does not count until release,
     * so releasing one makes a reply deliverable without any new message arriving. Every
     * release path stamps rippling_held_replies.releasedat, so both are covered by a
     * bounded query and no release path has to remember to tell us.
     *
     * $full re-checks every waiting message. That is what the nightly run does, and it is
     * the backstop for anything the two triggers above cannot see.
     *
     * @return array{waiting: int, received: int, checked: int, full: bool}
     */
    public function updateExpected(bool $dryRun = false, bool $full = false): array
    {
        // Both watermarks are taken BEFORE any work, so anything that arrives while we run
        // is left for the next run rather than being skipped by a cursor that moved past it.
        $startedAt = now();
        $highWater = (int) DB::table('chat_messages')->max('id');

        $oldest = now()->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateTimeString();
        $cursor = $this->readCursor();

        // No cursor yet means we have never run incrementally, so there is no safe delta to
        // take - do the whole thing and establish one.
        $doFull = $full || $cursor === null;

        $query = DB::table('chat_messages as cm')
            ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
            ->where('cm.date', '>=', $oldest)
            ->where('cm.replyexpected', 1)
            ->where('cm.replyreceived', 0)
            ->where('cr.chattype', ChatRoom::TYPE_USER2USER)
            ->select('cm.id', 'cm.userid', 'cm.chatid', 'cm.date', 'cr.user1', 'cr.user2');

        if (!$doFull) {
            $chatids = $this->chatsWithActivitySince($cursor);

            if (empty($chatids)) {
                if (!$dryRun) {
                    $this->writeCursor($startedAt, $highWater);
                }

                return ['waiting' => 0, 'received' => 0, 'checked' => 0, 'full' => false];
            }

            $query->whereIn('cm.chatid', $chatids);
        }

        $pending = $query->get();

        // What users_expected already says about these messages, so we only write where the
        // answer has actually changed.
        $existing = $this->existingExpectedValues($pending->pluck('id')->all());

        $waiting = 0;
        $received = 0;

        foreach ($pending as $msg) {
            $other = $msg->userid == $msg->user1 ? $msg->user2 : $msg->user1;

            $replyCount = DB::table('chat_messages')
                ->where('chatid', $msg->chatid)
                ->where('id', '>', $msg->id)
                ->where('userid', $other)
                // A rippling-held reply hasn't reached the expecter yet, so it must not count as
                // "reply received" (that would prematurely stop chase-up). Once released it counts.
                ->whereRaw(RippleReplyService::deliveryGateSql('chat_messages.id'))
                ->count();

            $value = $replyCount > 0 ? 1 : -1;

            if (!$dryRun) {
                if ($value === 1) {
                    DB::table('chat_messages')
                        ->where('id', $msg->id)
                        ->update(['replyreceived' => 1]);
                }

                if (($existing[$msg->id] ?? null) !== $value) {
                    // keep-raw: INSERT ... ON DUPLICATE KEY UPDATE against the chatmsgid
                    // unique key. upsert() would name every column in the update clause;
                    // this must only ever touch value.
                    DB::statement(
                        'INSERT INTO users_expected (expecter, expectee, chatmsgid, value)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)',
                        [$msg->userid, $other, $msg->id, $value]
                    );
                }
            }

            if ($value === 1) {
                $received++;
            } else {
                $waiting++;
            }
        }

        if (!$dryRun) {
            $this->writeCursor($startedAt, $highWater);
        }

        return ['waiting' => $waiting, 'received' => $received, 'checked' => $pending->count(), 'full' => $doFull];
    }

    /**
     * Chats where a reply could have become deliverable since the last run: one that had a
     * new message, and one whose held reply was released.
     *
     * @return int[]
     */
    private function chatsWithActivitySince(array $cursor): array
    {
        $chatids = DB::table('chat_messages')
            ->where('id', '>', $cursor['chatmsgid'])
            ->distinct()
            ->pluck('chatid')
            ->all();

        $releasedSince = Carbon::parse($cursor['at'])->subMinutes(self::RELEASE_OVERLAP_MINUTES)->toDateTimeString();

        $released = DB::table('rippling_held_replies')
            ->where('status', 'released')
            ->where('releasedat', '>=', $releasedSince)
            ->distinct()
            ->pluck('chatid')
            ->all();

        return array_values(array_unique(array_merge($chatids, $released)));
    }

    /**
     * Current users_expected values for these chat message ids, keyed by chatmsgid.
     *
     * @param  int[]  $chatmsgids
     * @return array<int, int>
     */
    private function existingExpectedValues(array $chatmsgids): array
    {
        $values = [];

        foreach (array_chunk($chatmsgids, 1000) as $chunk) {
            foreach (DB::table('users_expected')->whereIn('chatmsgid', $chunk)->select('chatmsgid', 'value')->get() as $row) {
                $values[(int) $row->chatmsgid] = (int) $row->value;
            }
        }

        return $values;
    }

    /**
     * @return array{chatmsgid: int, at: string}|null
     */
    private function readCursor(): ?array
    {
        $raw = DB::table('config')->where('key', self::CURSOR_KEY)->value('value');
        $decoded = $raw ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !isset($decoded['chatmsgid'], $decoded['at'])) {
            return null;
        }

        return ['chatmsgid' => (int) $decoded['chatmsgid'], 'at' => (string) $decoded['at']];
    }

    /**
     * Store where this run got to, using the watermarks taken before the work started.
     */
    private function writeCursor(Carbon $startedAt, int $highWater): void
    {
        $value = json_encode([
            'chatmsgid' => $highWater,
            'at' => $startedAt->toDateTimeString(),
        ]);

        DB::table('config')->upsert(
            [['key' => self::CURSOR_KEY, 'value' => $value]],
            ['key'],
            ['value'],
        );
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
            $msgs = DB::table('chat_messages as cm')
                ->select('cm.id', 'cm.chatid', 'cm.date', 'cr.user1', 'cr.user2')
                ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
                ->where('cm.userid', $userId)
                ->where('cm.date', '>', $since)
                ->where('cr.chattype', ChatRoom::TYPE_USER2USER)
                ->whereIn('cm.type', [ChatMessage::TYPE_INTERESTED, ChatMessage::TYPE_DEFAULT])
                ->get()
                ->all();

            $delays = [];

            foreach ($msgs as $msg) {
                $other = $msg->user1 == $userId ? $msg->user2 : $msg->user1;

                // Check if the other user has an outstanding un-replied message
                $outstanding = DB::table('chat_messages as cm')
                    ->select('cm.date')
                    ->where('cm.chatid', $msg->chatid)
                    ->where('cm.userid', $other)
                    ->where('cm.id', '>', $msg->id)
                    ->orderByDesc('cm.id')
                    ->limit(1)
                    ->get()
                    ->all();

                if (!empty($outstanding)) {
                    // Other user replied — compute how long it took
                    $delay = strtotime($outstanding[0]->date) - strtotime($msg->date);
                    if ($delay > 0 && $delay < $maxDelay) {
                        $delays[] = $delay;
                    }
                } else {
                    // No reply from the other user: find if we're waiting on them
                    // ->max() rather than ->select(DB::raw('MAX(date) AS max')):
                    // DB::raw() is itself a raw site in the inventory (surface
                    // "expression"), so wrapping the aggregate in it would move
                    // this site between surfaces rather than remove it - the raw
                    // count would not drop and nothing would have been converted.
                    $lastOtherMax = DB::table('chat_messages')
                        ->where('chatid', $msg->chatid)
                        ->where('id', '<', $msg->id)
                        ->where('userid', $other)
                        ->max('date');

                    if ($lastOtherMax) {
                        $delay = strtotime($msg->date) - strtotime($lastOtherMax);
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
