<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates the daily `stats` table rows that V1's Stats::generate() wrote.
 *
 * V1 source: the legacy V1 PHP Stats class and its group_stats cron script.
 * V1 invocation (group_stats.php line 93-99): for $date = yesterday, for every
 * row in `groups`, call Stats::generate($date) which REPLACEd one row per
 * stat-type into `stats(date, groupid, type, count|breakdown)`.
 *
 * V2's groups:update-stats only did the metadata maintenance (repost settings,
 * polyindex, funding, mod counts). The per-day per-type aggregation was never
 * ported, so the table stopped updating after the V1 cutover (last row
 * 2026-05-07; cutover happened the same day).
 *
 * The site is now a single national community with one pool of moderators, so
 * every figure below is a national scalar rather than a per-group breakdown -
 * there is no `groups` table left to key on.
 */
class StatsGenerationService
{
    public const TYPE_OUTCOMES = 'Outcomes';
    public const TYPE_APPROVED_MESSAGE_COUNT = 'ApprovedMessageCount';
    public const TYPE_APPROVED_MEMBER_COUNT = 'ApprovedMemberCount';
    public const TYPE_SPAM_MESSAGE_COUNT = 'SpamMessageCount';
    public const TYPE_SPAM_MEMBER_COUNT = 'SpamMemberCount';
    public const TYPE_SUPPORTQUERIES_COUNT = 'SupportQueries';
    public const TYPE_FEEDBACK_HAPPY = 'Happy';
    public const TYPE_FEEDBACK_FINE = 'Fine';
    public const TYPE_FEEDBACK_UNHAPPY = 'Unhappy';
    public const TYPE_POST_METHOD_BREAKDOWN = 'PostMethodBreakdown';
    public const TYPE_MESSAGE_BREAKDOWN = 'MessageBreakdown';
    public const TYPE_OUR_POSTING_BREAKDOWN = 'OurPostingBreakdown';
    public const TYPE_SEARCHES = 'Searches';
    public const TYPE_REPLIES = 'Replies';
    public const TYPE_WEIGHT = 'Weight';
    public const TYPE_ACTIVE_USERS = 'ActiveUsers';
    public const TYPE_ACTIVITY = 'Activity';

    /**
     * Generate every stat type for the given date.
     *
     * @return array{rows_written: int}
     */
    public function generateForDate(string $date, bool $dryRun = false): array
    {
        $context = $this->buildDailyContext($date);
        $rowsWritten = $this->generate($date, $dryRun, $context);

        return ['rows_written' => $rowsWritten];
    }

    /**
     * Build the per-day national values so generate() can write every stat type
     * from a single set of already-computed scalars instead of each write
     * issuing its own queries.
     *
     * Keys:
     *  - avgWeight: float - popularity-weighted population mean item weight,
     *    used as the fallback when an item's own weight is unknown or zero.
     *  - searches/postMethod/messageTypes/activeUsers - search tally and
     *    30-day-window breakdowns/active-user count.
     *  - outcomes/approvedMessages/approvedMembers/spamMessages/spamMembers/
     *    supportQueries/replies/weight - national counts for $date.
     *  - feedback - happiness histogram (Happy/Fine/Unhappy).
     *  - ourPosting - users.postingstatus histogram (point-in-time, not
     *    date-windowed).
     *
     * @return array{avgWeight: float, searches: int, postMethod: array<string,int>, messageTypes: array<string,int>, activeUsers: int, outcomes: int, approvedMessages: int, approvedMembers: int, spamMessages: int, spamMembers: int, supportQueries: int, replies: int, weight: int, feedback: array<string,int>, ourPosting: array<string,int>}
     */
    private function buildDailyContext(string $date): array
    {
        // Half-open day range [$date, $next) - exactly equivalent to the
        // original DATE(col) = $date predicate for datetime columns, but
        // sargable. $next is also reused by the cumulative member count.
        $next = CarbonImmutable::parse($date)->addDay()->toDateString();

        $avg = $this->averageItemWeight();

        // SEARCHES: national count of searches made on $date.
        $searches = (int) DB::table('search_history')
            ->where('date', '>=', $date)
            ->where('date', '<', $next)
            ->count();

        // 30-day rolling window shared by the breakdown + active-user stats.
        $windowStart = date('Y-m-d', strtotime('30 days ago', strtotime($date)));
        $windowEnd = date('Y-m-d', strtotime('tomorrow', strtotime($date)));

        // POST_METHOD_BREAKDOWN: sourceheader histogram for approved messages in the window.
        $postMethod = [];
        foreach (
            DB::table('messages')
                ->where('arrival', '>=', $windowStart)
                ->where('arrival', '<', $windowEnd)
                ->where('collection', Message::COLLECTION_APPROVED)
                ->whereNotNull('sourceheader')
                ->groupBy('sourceheader')
                ->selectRaw('sourceheader AS source, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $postMethod[$row->source] = (int) $row->cnt;
        }

        // MESSAGE_BREAKDOWN: message-type histogram for approved messages in the window.
        $messageTypes = [];
        foreach (
            DB::table('messages')
                ->where('arrival', '>=', $windowStart)
                ->where('arrival', '<', $windowEnd)
                ->where('collection', Message::COLLECTION_APPROVED)
                ->whereNotNull('type')
                ->groupBy('type')
                ->selectRaw('type, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $messageTypes[$row->type] = (int) $row->cnt;
        }

        // ACTIVE_USERS: distinct users active in the window.
        $activeUsers = (int) (DB::table('users_active')
            ->where('timestamp', '>=', $windowStart)
            ->where('timestamp', '<', $windowEnd)
            ->selectRaw('COUNT(DISTINCT userid) AS cnt')
            ->value('cnt') ?? 0);

        // OUTCOMES non-bulk: distinct messages with a Taken/Received outcome dated $date,
        // excluding bulk-offer messages (counted via available=0 flips below).
        $outcomes = (int) (DB::table('messages_outcomes')
            ->where('timestamp', '>=', $date)
            ->where('timestamp', '<', $next)
            ->whereIn('outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_bulk_items')
                    ->whereColumn('messages_bulk_items.msgid', 'messages_outcomes.msgid');
            })
            ->selectRaw('COUNT(DISTINCT msgid) AS cnt')
            ->value('cnt') ?? 0);

        // OUTCOMES bulk flip: catalogue items flipped to available=0 on $date count by
        // their quantity (remaining units at flip time).
        $outcomes += (int) (DB::table('messages_bulk_items')
            ->where('available', 0)
            ->where('updated_at', '>=', $date)
            ->where('updated_at', '<', $next)
            ->sum('quantity') ?? 0);

        // OUTCOMES collected: in-app collections (interest rows flipped to Collected on $date)
        // count by the interest-row quantity. These units were already deducted from
        // messages_bulk_items.quantity before any flip, so there is no overlap with the
        // available=0 arm above.
        $outcomes += (int) (DB::table('messages_bulk_items_interest')
            ->where('state', 'Collected')
            ->where('updated_at', '>=', $date)
            ->where('updated_at', '<', $next)
            ->sum('quantity') ?? 0);

        // APPROVED_MESSAGE_COUNT: distinct approved messages arriving $date.
        $approvedMessages = (int) DB::table('messages')
            ->where('arrival', '>=', $date)
            ->where('arrival', '<', $next)
            ->where('collection', Message::COLLECTION_APPROVED)
            ->count();

        // BULK TOP-UP: bulk messages count by initial unit total (messages.availableinitially),
        // not 1 per message. The base query above already counted each bulk message as 1; add
        // availableinitially - 1 per bulk message. Using availableinitially (set at creation)
        // keeps the arrival-day figure immune to same-day collections shrinking
        // messages_bulk_items.quantity.
        $topup = (int) (DB::table('messages')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_bulk_items')
                    ->whereColumn('messages_bulk_items.msgid', 'messages.id');
            })
            ->where('arrival', '>=', $date)
            ->where('arrival', '<', $next)
            ->where('collection', Message::COLLECTION_APPROVED)
            ->selectRaw('SUM(availableinitially - 1) AS topup')
            ->value('topup') ?? 0);
        if ($topup > 0) {
            $approvedMessages += $topup;
        }

        // APPROVED_MEMBER_COUNT: cumulative users signed up by $date who are not banned as
        // of $date. There is no more per-group membership row to count approved members
        // in, so this is read directly off users as the national equivalent - everyone who
        // has joined and is still in good standing.
        $approvedMembers = (int) DB::table('users')
            ->where('added', '<', $next)
            ->where(function ($q) use ($next) {
                $q->whereNull('banned')->orWhere('banned', '>=', $next);
            })
            ->count();

        // SPAM_MESSAGE_COUNT: ClassifiedSpam Message log entries on $date.
        $spamMessages = (int) DB::table('logs')
            ->where('timestamp', '>=', $date)
            ->where('timestamp', '<', $next)
            ->where('type', 'Message')
            ->where('subtype', 'ClassifiedSpam')
            ->count();

        // SPAM_MEMBER_COUNT: users banned on $date. The old definition was "a known
        // spammer who left a group", which has no group to leave now - being banned is
        // the national equivalent verdict.
        $spamMembers = (int) DB::table('users')
            ->whereNotNull('banned')
            ->where('banned', '>=', $date)
            ->where('banned', '<', $next)
            ->count();

        // SUPPORTQUERIES_COUNT: User2Mod chats created on $date.
        $supportQueries = (int) DB::table('chat_rooms')
            ->where('created', '>=', $date)
            ->where('created', '<', $next)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->count();

        // FEEDBACK: happiness histogram (Happy/Fine/Unhappy) for outcomes dated $date.
        $feedback = [];
        foreach (
            DB::table('messages_outcomes')
                ->whereIn('happiness', [
                    self::TYPE_FEEDBACK_HAPPY,
                    self::TYPE_FEEDBACK_FINE,
                    self::TYPE_FEEDBACK_UNHAPPY,
                ])
                ->where('timestamp', '>=', $date)
                ->where('timestamp', '<', $next)
                ->groupBy('happiness')
                ->selectRaw('happiness, COUNT(DISTINCT msgid) AS cnt')
                ->get() as $row
        ) {
            $feedback[$row->happiness] = (int) $row->cnt;
        }

        // OUR_POSTING_BREAKDOWN: users.postingstatus histogram (point-in-time, not
        // date-windowed). A null status maps to the "" key exactly as the original
        // per-membership ourPostingStatus histogram did.
        $ourPosting = [];
        foreach (
            DB::table('users')
                ->groupBy('postingstatus')
                ->selectRaw('postingstatus, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $ourPosting[$row->postingstatus ?? ''] = (int) $row->cnt;
        }

        // REPLIES non-bulk: "Interested" chat messages referring to approved non-bulk
        // posts on $date.
        $replies = (int) DB::table('chat_messages')
            ->where('date', '>=', $date)
            ->where('date', '<', $next)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_bulk_items')
                    ->whereColumn('messages_bulk_items.msgid', 'chat_messages.refmsgid');
            })
            ->whereNotExists(fn ($q) => $this->senderOnSpammerList($q, 'chat_messages.userid'))
            ->count();

        // REPLIES bulk part 1: structured interest rows created on $date - one row
        // per (item, user) pair counts as one reply signal.
        $replies += (int) DB::table('messages_bulk_items_interest')
            ->where('created_at', '>=', $date)
            ->where('created_at', '<', $next)
            ->whereNotExists(fn ($q) => $this->senderOnSpammerList($q, 'messages_bulk_items_interest.userid'))
            ->count();

        // REPLIES bulk part 2: free-text Interested chat messages for bulk msgids
        // where the sender has no interest row - they replied outside the structured
        // flow and would otherwise be missed entirely.
        $replies += (int) DB::table('chat_messages')
            ->where('date', '>=', $date)
            ->where('date', '<', $next)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_bulk_items')
                    ->whereColumn('messages_bulk_items.msgid', 'chat_messages.refmsgid');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_bulk_items_interest')
                    ->whereColumn('messages_bulk_items_interest.msgid', 'chat_messages.refmsgid')
                    ->whereColumn('messages_bulk_items_interest.userid', 'chat_messages.userid');
            })
            ->whereNotExists(fn ($q) => $this->senderOnSpammerList($q, 'chat_messages.userid'))
            ->count();

        // WEIGHT: estimated kg of items moved on $date - see dailyWeight().
        $weight = $this->dailyWeight($date, $next, $avg);

        return [
            'avgWeight' => $avg,
            'searches' => $searches,
            'postMethod' => $postMethod,
            'messageTypes' => $messageTypes,
            'activeUsers' => $activeUsers,
            'outcomes' => $outcomes,
            'approvedMessages' => $approvedMessages,
            'approvedMembers' => $approvedMembers,
            'spamMessages' => $spamMessages,
            'spamMembers' => $spamMembers,
            'supportQueries' => $supportQueries,
            'replies' => $replies,
            'weight' => $weight,
            'feedback' => $feedback,
            'ourPosting' => $ourPosting,
        ];
    }

    /**
     * Generate all stat types for a single date.
     *
     * Mirrors V1 Stats::generate($date) with all 16 type branches plus the
     * derived ACTIVITY (sum of APPROVED_MESSAGE_COUNT + REPLIES). Re-running
     * the same date overwrites prior rows via writeCount()/writeBreakdown(),
     * so it is safe for backfills.
     *
     * Returns the number of `stats` rows touched (for accounting/dry-run).
     *
     * $context may be passed by generateForDate() to reuse work already done
     * for this date. When null it is computed here - useful for ad-hoc calls
     * and tests.
     */
    public function generate(string $date, bool $dryRun = false, ?array $context = null): int
    {
        $context ??= $this->buildDailyContext($date);
        $rows = 0;

        // Every value below is precomputed once per date in buildDailyContext(),
        // so generate() issues no aggregation queries of its own - only context
        // lookups plus writeCount()/writeBreakdown().

        // OUTCOMES: distinct messages with a Taken/Received outcome dated $date.
        $rows += $this->writeCount($date, self::TYPE_OUTCOMES, $context['outcomes'], $dryRun);

        // APPROVED_MESSAGE_COUNT: distinct approved messages whose arrival is $date. Also feeds ACTIVITY.
        $approvedMessages = $context['approvedMessages'];
        $rows += $this->writeCount($date, self::TYPE_APPROVED_MESSAGE_COUNT, $approvedMessages, $dryRun);

        // APPROVED_MEMBER_COUNT: cumulative approved members as of $date.
        $rows += $this->writeCount($date, self::TYPE_APPROVED_MEMBER_COUNT, $context['approvedMembers'], $dryRun);

        // SPAM_MESSAGE_COUNT: ClassifiedSpam log entries for messages on $date.
        $rows += $this->writeCount($date, self::TYPE_SPAM_MESSAGE_COUNT, $context['spamMessages'], $dryRun);

        // SPAM_MEMBER_COUNT: users banned on $date.
        $rows += $this->writeCount($date, self::TYPE_SPAM_MEMBER_COUNT, $context['spamMembers'], $dryRun);

        // SUPPORTQUERIES_COUNT: User2Mod chats created on $date.
        $rows += $this->writeCount($date, self::TYPE_SUPPORTQUERIES_COUNT, $context['supportQueries'], $dryRun);

        // Feedback breakdown (happy/fine/unhappy) - read each bucket from the
        // precomputed happiness histogram.
        foreach ([
            self::TYPE_FEEDBACK_HAPPY,
            self::TYPE_FEEDBACK_FINE,
            self::TYPE_FEEDBACK_UNHAPPY,
        ] as $happiness) {
            $rows += $this->writeCount($date, $happiness, $context['feedback'][$happiness] ?? 0, $dryRun);
        }

        // POST_METHOD_BREAKDOWN + MESSAGE_BREAKDOWN: sourceheader / message-type
        // histograms for approved messages in the 30-day window.
        $rows += $this->writeBreakdown($date, self::TYPE_POST_METHOD_BREAKDOWN, $context['postMethod'], $dryRun);
        $rows += $this->writeBreakdown($date, self::TYPE_MESSAGE_BREAKDOWN, $context['messageTypes'], $dryRun);

        // OUR_POSTING_BREAKDOWN: users.postingstatus histogram (point-in-time, not date-windowed).
        $rows += $this->writeBreakdown($date, self::TYPE_OUR_POSTING_BREAKDOWN, $context['ourPosting'], $dryRun);

        // SEARCHES: national tally pre-computed once per day from search_history.
        $rows += $this->writeCount($date, self::TYPE_SEARCHES, $context['searches'], $dryRun);

        // REPLIES: "Interested" chat messages referring to approved posts on $date. Also feeds ACTIVITY.
        $replies = $context['replies'];
        $rows += $this->writeCount($date, self::TYPE_REPLIES, $replies, $dryRun);

        // WEIGHT: estimated kg of items moved on $date. Uses items.weight when known,
        // otherwise the popularity-weighted population mean (V1 logic).
        $rows += $this->writeCount($date, self::TYPE_WEIGHT, $context['weight'], $dryRun);

        // ACTIVE_USERS: distinct users active in the 30 days ending tomorrow-of-$date.
        $rows += $this->writeCount($date, self::TYPE_ACTIVE_USERS, $context['activeUsers'], $dryRun);

        // ACTIVITY: rolled-up "things happened today" - approved-messages + replies (V1 formula).
        $rows += $this->writeCount($date, self::TYPE_ACTIVITY, $approvedMessages + $replies, $dryRun);

        return $rows;
    }

    /**
     * Fast Weight-only regeneration across a date range.
     *
     * The full generate() pipeline runs 16 type-specific aggregations per date.
     * Only the WEIGHT type depends on messages_items (the table the cutover
     * backfill repaired), so the other 15 are pure waste when the goal is just
     * to recover the Weight figures.
     *
     * avgWeight is hoisted across the whole range (it's a function of the
     * items table, not the date); each date's weight is then written through
     * the same writeCount() used by generate(), so a date whose weight comes
     * out at zero has its stale row removed rather than left standing.
     *
     * CO2 and reuse-value are NOT stored - they're pure functions of Weight
     * applied at read time by misc/ReuseBenefit.php, so fixing Weight here
     * implicitly fixes them too.
     *
     * @return array{datesProcessed: int, rowsWritten: int}
     */
    public function regenerateWeightForRange(string $from, string $to, bool $dryRun = false): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        $avg = $this->averageItemWeight();

        $datesProcessed = 0;
        $rowsWritten = 0;

        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $next = $d->addDay()->toDateString();

            $weight = $this->dailyWeight($date, $next, $avg);

            $datesProcessed++;
            $rowsWritten += $this->writeCount($date, self::TYPE_WEIGHT, $weight, $dryRun);
        }

        return ['datesProcessed' => $datesProcessed, 'rowsWritten' => $rowsWritten];
    }

    /**
     * Popularity-weighted population mean item weight, used as the fallback
     * whenever a specific item's own weight is unknown or zero.
     */
    private function averageItemWeight(): float
    {
        return (float) (DB::table('items')
            ->whereNotNull('weight')
            ->where('weight', '!=', 0)
            ->selectRaw('SUM(popularity * weight) / SUM(popularity) AS average')
            ->value('average') ?? 0);
    }

    /**
     * Estimated kg of items moved on the half-open range [$date, $next),
     * combining the three ways an item leaves the pool: an outcome recorded
     * against a non-bulk message, a bulk catalogue item flipped to
     * available=0, and an in-app bulk collection. Uses items.weight when
     * known and non-zero, otherwise falls back to $avg.
     *
     * Bulk items are matched to `items` by name rather than id (bulk offers
     * are not linked via messages_items), so the join needs an explicit
     * collation: messages_bulk_items is created with MySQL 8's default
     * collation (utf8mb4_0900_ai_ci) while items.name is utf8mb4_unicode_ci,
     * and joining the two without COLLATE throws SQLSTATE[HY000] 1267.
     */
    private function dailyWeight(string $date, string $next, float $avg): int
    {
        $nonBulk = (int) ((DB::selectOne(
            'SELECT ROUND(SUM(sub.eff_weight)) AS total_weight '
            . 'FROM ('
            . '  SELECT DISTINCT mo.msgid, '
            . '    COALESCE(NULLIF(i.weight, 0), ?) AS eff_weight '
            . '  FROM messages_outcomes mo '
            . '  INNER JOIN messages_items mi ON mi.msgid = mo.msgid '
            . '  LEFT JOIN items i ON i.id = mi.itemid '
            . '  WHERE mo.timestamp >= ? AND mo.timestamp < ? '
            . '    AND mo.outcome IN (?, ?)'
            . '    AND NOT EXISTS (SELECT 1 FROM messages_bulk_items bxi WHERE bxi.msgid = mo.msgid)'
            . ') sub',
            [$avg, $date, $next, Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED]
        )?->total_weight) ?? 0);

        $bulk = (int) ((DB::selectOne(
            'SELECT ROUND(SUM(COALESCE(NULLIF(i.weight, 0), ?) * bi.quantity)) AS bulk_weight '
            . 'FROM messages_bulk_items bi '
            . 'LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci '
            . 'WHERE bi.available = 0 '
            . '  AND bi.updated_at >= ? AND bi.updated_at < ?',
            [$avg, $date, $next]
        )?->bulk_weight) ?? 0);

        $collected = (int) ((DB::selectOne(
            'SELECT ROUND(SUM(COALESCE(NULLIF(i.weight, 0), ?) * mbi.quantity)) AS coll_weight '
            . 'FROM messages_bulk_items_interest mbi '
            . 'INNER JOIN messages_bulk_items bi ON bi.id = mbi.bulkitemid '
            . 'LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci '
            . 'WHERE mbi.state = ? '
            . '  AND mbi.updated_at >= ? AND mbi.updated_at < ?',
            [$avg, 'Collected', $date, $next]
        )?->coll_weight) ?? 0);

        return $nonBulk + $bulk + $collected;
    }

    /**
     * Write (or clear) one count row. A regeneration that brings a count down
     * to zero must delete the old row, not leave it standing - after the
     * 2026-09-06 spam wave was excluded from Replies, a re-run that only
     * skipped writing zero rows would have left the inflated pre-exclusion
     * figure in place.
     */
    private function writeCount(string $date, string $type, int $val, bool $dryRun): int
    {
        if ($val === 0) {
            if (! $dryRun) {
                DB::table('stats')
                    ->where('date', $date)
                    ->where('type', $type)
                    ->delete();
            }

            return 0;
        }

        if ($dryRun) {
            return 1;
        }

        DB::table('stats')->updateOrInsert(
            ['date' => $date, 'type' => $type],
            ['count' => $val]
        );

        return 1;
    }

    /**
     * Write one breakdown row unconditionally - '[]'/'{}' is meaningful to
     * downstream consumers, so an empty breakdown is still written rather
     * than skipped.
     */
    private function writeBreakdown(string $date, string $type, array $map, bool $dryRun): int
    {
        if ($dryRun) {
            return 1;
        }

        DB::table('stats')->updateOrInsert(
            ['date' => $date, 'type' => $type],
            ['breakdown' => json_encode($map, JSON_UNESCAPED_UNICODE)]
        );

        return 1;
    }

    /**
     * The "sender is on the spammer list" subquery every reply source excludes on.
     *
     * A reply from a listed spammer is not a reply: on 2026-09-06 one account created
     * the evening before sent 2,155 blank "Interested" messages to 2,155 different posts
     * in twenty minutes, every one rejected in chat review and none delivered - and the
     * day's Replies stat (and Activity, which is built on it) still counted them, more
     * than doubling a Sunday. The listing is the moderators' verdict, so it is the
     * exclusion here too; the review flags on individual messages are not consulted,
     * because a listed account's earlier, un-reviewed messages are just as worthless.
     */
    private function senderOnSpammerList(\Illuminate\Database\Query\Builder $q, string $userColumn): \Illuminate\Database\Query\Builder
    {
        return $q->select(DB::raw(1))
            ->from('spam_users')
            ->whereColumn('spam_users.userid', $userColumn)
            ->where('spam_users.collection', 'Spammer');
    }
}
