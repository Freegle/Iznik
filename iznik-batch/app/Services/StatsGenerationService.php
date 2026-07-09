<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Membership;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates the daily `stats` table rows that V1's Stats::generate() wrote.
 *
 * V1 source: the legacy V1 PHP Stats class and its group_stats cron script.
 * V1 invocation (group_stats.php line 93-99): for $date = yesterday, for every
 * row in `groups`, call Stats::generate($date) which REPLACEs one row per
 * stat-type into `stats(date, groupid, type, count|breakdown)`.
 *
 * V2's groups:update-stats only did the metadata maintenance (repost settings,
 * polyindex, funding, mod counts). The per-day per-type aggregation was never
 * ported, so the table stopped updating after the V1 cutover (last row
 * 2026-05-07; cutover happened the same day).
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
     * Generate stats for every group for the given date.
     *
     * @return array{groups: int, rows_written: int}
     */
    public function generateForAllGroups(string $date, bool $dryRun = false): array
    {
        $groupIds = DB::table('groups')->orderBy('id')->pluck('id');

        // Per-day work hoisted out of the per-group loop. Without these the
        // 7-day backfill was running the same searches/items scan 600× per
        // day for ~30s of duplicated work.
        $context = $this->buildDailyContext($date);

        $rowsWritten = 0;
        foreach ($groupIds as $groupId) {
            $rowsWritten += $this->generate((int) $groupId, $date, $dryRun, $context);
        }

        return [
            'groups' => $groupIds->count(),
            'rows_written' => $rowsWritten,
        ];
    }

    /**
     * Build the per-day shared values so we compute them ONCE per date and pass
     * them into each per-group `generate()` call, instead of running ~17
     * aggregations per group (~6,600 queries per date for ~506 groups). Every
     * value here is a pure function of the date, computed as a single query
     * that aggregates `GROUP BY groupid`; generate() then just reads
     * `$context[...][$groupId]` with a safe default. Each grouped query
     * preserves the EXACT WHERE clauses / DISTINCT semantics of the original
     * per-group query it replaces, so per-group results are identical with no
     * new index.
     *
     * Keys:
     *  - avgWeight: float — popularity-weighted population mean item weight.
     *  - searchCounts/postMethod/messageTypes/activeUsers — search tally and
     *    30-day-window breakdowns/active-users (date-window stats).
     *  - outcomes/approvedMessages/approvedMembers/spamMessages/spamMembers/
     *    supportQueries/replies/weight — per-day per-group counts.
     *  - feedback — per-group happiness histogram (Happy/Fine/Unhappy).
     *  - ourPosting — per-group ourPostingStatus histogram (point-in-time).
     *
     * @return array{avgWeight: float, searchCounts: array<int,int>, postMethod: array<int,array<string,int>>, messageTypes: array<int,array<string,int>>, activeUsers: array<int,int>, outcomes: array<int,int>, approvedMessages: array<int,int>, approvedMembers: array<int,int>, spamMessages: array<int,int>, spamMembers: array<int,int>, supportQueries: array<int,int>, replies: array<int,int>, weight: array<int,int>, feedback: array<int,array<string,int>>, ourPosting: array<int,array<string,int>>}
     */
    private function buildDailyContext(string $date): array
    {
        // Half-open day range [$date, $next) — exactly equivalent to the
        // original DATE(col) = $date predicate for datetime columns, but
        // sargable. $next is also reused by the cumulative member count.
        $next = CarbonImmutable::parse($date)->addDay()->toDateString();

        $avg = (float) (DB::table('items')
            ->whereNotNull('weight')
            ->where('weight', '!=', 0)
            ->selectRaw('SUM(popularity * weight) / SUM(popularity) AS average')
            ->value('average') ?? 0);

        // V1's search_history.groups is a comma-separated string. One scan,
        // tally per-group, vs the previous "scan per group" approach.
        $searchCounts = [];
        DB::table('search_history')
            ->where('date', '>=', $date)
            ->whereRaw('DATE(date) = ?', [$date])
            ->whereNotNull('groups')
            ->where('groups', '!=', '')
            ->select('groups')
            ->orderBy('id')
            ->chunk(1000, function ($searches) use (&$searchCounts) {
                foreach ($searches as $search) {
                    foreach (explode(',', $search->groups) as $gid) {
                        $gid = (int) trim($gid);
                        if ($gid === 0) {
                            continue;
                        }
                        $searchCounts[$gid] = ($searchCounts[$gid] ?? 0) + 1;
                    }
                }
            });

        // 30-day rolling window shared by the breakdown + active-user stats.
        $windowStart = date('Y-m-d', strtotime('30 days ago', strtotime($date)));
        $windowEnd = date('Y-m-d', strtotime('tomorrow', strtotime($date)));

        // POST_METHOD_BREAKDOWN: sourceheader histogram for approved native
        // messages in the window, bucketed by group in one scan.
        $postMethod = [];
        foreach (
            DB::table('messages')
                ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
                ->where('messages.arrival', '>=', $windowStart)
                ->where('messages.arrival', '<', $windowEnd)
                ->where('messages_groups.collection', 'Approved')
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->whereNotNull('messages.sourceheader')
                ->groupBy('messages_groups.groupid', 'messages.sourceheader')
                ->selectRaw('messages_groups.groupid AS gid, messages.sourceheader AS source, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $postMethod[(int) $row->gid][$row->source] = (int) $row->cnt;
        }

        // MESSAGE_BREAKDOWN: message-type histogram for approved native
        // messages in the window, bucketed by group in one scan.
        $messageTypes = [];
        foreach (
            DB::table('messages')
                ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
                ->where('messages.arrival', '>=', $windowStart)
                ->where('messages.arrival', '<', $windowEnd)
                ->where('messages_groups.collection', 'Approved')
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->whereNotNull('messages.type')
                ->groupBy('messages_groups.groupid', 'messages.type')
                ->selectRaw('messages_groups.groupid AS gid, messages.type AS type, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $messageTypes[(int) $row->gid][$row->type] = (int) $row->cnt;
        }

        // ACTIVE_USERS: distinct members active in the window, per group, in
        // one scan grouped by groupid.
        $activeUsers = [];
        foreach (
            DB::table('users_active')
                ->join('memberships', 'memberships.userid', '=', 'users_active.userid')
                ->where('users_active.timestamp', '>=', $windowStart)
                ->where('users_active.timestamp', '<', $windowEnd)
                ->groupBy('memberships.groupid')
                ->selectRaw('memberships.groupid AS gid, COUNT(DISTINCT users_active.userid) AS cnt')
                ->get() as $row
        ) {
            $activeUsers[(int) $row->gid] = (int) $row->cnt;
        }

        // OUTCOMES non-bulk: distinct messages with a Taken/Received outcome dated $date,
        // per native group, excluding bulk-offer messages (counted via available=0 flips below).
        $outcomes = [];
        foreach (
            DB::table('messages_outcomes')
                ->join('messages_groups', 'messages_outcomes.msgid', '=', 'messages_groups.msgid')
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->where('messages_outcomes.timestamp', '>=', $date)
                ->where('messages_outcomes.timestamp', '<', $next)
                ->whereIn('messages_outcomes.outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED])
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('messages_bulk_items')
                        ->whereColumn('messages_bulk_items.msgid', 'messages_outcomes.msgid');
                })
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, COUNT(DISTINCT messages_outcomes.msgid) AS cnt')
                ->get() as $row
        ) {
            $outcomes[(int) $row->gid] = (int) $row->cnt;
        }

        // OUTCOMES bulk flip: catalogue items flipped to available=0 on $date count by
        // their quantity (remaining units at flip time).
        foreach (
            DB::table('messages_bulk_items')
                ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_bulk_items.msgid')
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->where('messages_bulk_items.available', 0)
                ->where('messages_bulk_items.updated_at', '>=', $date)
                ->where('messages_bulk_items.updated_at', '<', $next)
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, SUM(messages_bulk_items.quantity) AS cnt')
                ->get() as $row
        ) {
            $gid = (int) $row->gid;
            $outcomes[$gid] = ($outcomes[$gid] ?? 0) + (int) $row->cnt;
        }

        // OUTCOMES collected: in-app collections (interest rows flipped to Collected on $date)
        // count by the interest-row quantity. These units were already deducted from
        // messages_bulk_items.quantity before any flip, so there is no overlap with the
        // available=0 arm above.
        foreach (
            DB::table('messages_bulk_items_interest')
                ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_bulk_items_interest.msgid')
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->where('messages_bulk_items_interest.state', 'Collected')
                ->where('messages_bulk_items_interest.updated_at', '>=', $date)
                ->where('messages_bulk_items_interest.updated_at', '<', $next)
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, SUM(messages_bulk_items_interest.quantity) AS cnt')
                ->get() as $row
        ) {
            $gid = (int) $row->gid;
            $outcomes[$gid] = ($outcomes[$gid] ?? 0) + (int) $row->cnt;
        }

        // APPROVED_MESSAGE_COUNT: distinct approved native messages arriving $date.
        $approvedMessages = [];
        foreach (
            DB::table('messages_groups')
                ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->where('messages.arrival', '>=', $date)
                ->where('messages.arrival', '<', $next)
                ->where('messages_groups.collection', Membership::COLLECTION_APPROVED)
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, COUNT(DISTINCT messages_groups.msgid) AS cnt')
                ->get() as $row
        ) {
            $approvedMessages[(int) $row->gid] = (int) $row->cnt;
        }

        // BULK TOP-UP: bulk messages count by initial unit total (messages.availableinitially),
        // not 1 per message. The base query already counted each bulk message as 1; add
        // availableinitially - 1 per bulk message per group. Using availableinitially (set at
        // creation) keeps the arrival-day figure immune to same-day collections shrinking
        // messages_bulk_items.quantity.
        foreach (
            DB::table('messages_groups')
                ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('messages_bulk_items')
                        ->whereColumn('messages_bulk_items.msgid', 'messages_groups.msgid');
                })
                ->where('messages_groups.rippled_in', 0)
                ->where('messages.arrival', '>=', $date)
                ->where('messages.arrival', '<', $next)
                ->where('messages_groups.collection', Membership::COLLECTION_APPROVED)
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, SUM(messages.availableinitially - 1) AS topup')
                ->get() as $row
        ) {
            $gid = (int) $row->gid;
            $topup = (int) $row->topup;
            if ($topup > 0) {
                $approvedMessages[$gid] = ($approvedMessages[$gid] ?? 0) + $topup;
            }
        }

        // APPROVED_MEMBER_COUNT: cumulative approved members as of $date
        // (DATE(added) <= $date ⇔ added < $next).
        $approvedMembers = [];
        foreach (
            DB::table('memberships')
                ->where('added', '<', $next)
                ->where('collection', Membership::COLLECTION_APPROVED)
                ->groupBy('groupid')
                ->selectRaw('groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $approvedMembers[(int) $row->gid] = (int) $row->cnt;
        }

        // SPAM_MESSAGE_COUNT: ClassifiedSpam Message log entries on $date.
        $spamMessages = [];
        foreach (
            DB::table('logs')
                ->where('timestamp', '>=', $date)
                ->where('timestamp', '<', $next)
                ->where('type', 'Message')
                ->where('subtype', 'ClassifiedSpam')
                ->whereNotNull('groupid')
                ->groupBy('groupid')
                ->selectRaw('groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $spamMessages[(int) $row->gid] = (int) $row->cnt;
        }

        // SPAM_MEMBER_COUNT: known spammers who left a group on $date.
        $spamMembers = [];
        foreach (
            DB::table('logs')
                ->join('spam_users', function ($j) {
                    $j->on('logs.user', '=', 'spam_users.userid')
                        ->where('spam_users.collection', '=', 'Spammer');
                })
                ->where('logs.timestamp', '>=', $date)
                ->where('logs.timestamp', '<', $next)
                ->where('logs.type', 'Group')
                ->where('logs.subtype', 'Left')
                ->whereNotNull('logs.groupid')
                ->groupBy('logs.groupid')
                ->selectRaw('logs.groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $spamMembers[(int) $row->gid] = (int) $row->cnt;
        }

        // SUPPORTQUERIES_COUNT: User2Mod chats created on $date.
        $supportQueries = [];
        foreach (
            DB::table('chat_rooms')
                ->where('created', '>=', $date)
                ->where('created', '<', $next)
                ->where('chattype', ChatRoom::TYPE_USER2MOD)
                ->whereNotNull('groupid')
                ->groupBy('groupid')
                ->selectRaw('groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $supportQueries[(int) $row->gid] = (int) $row->cnt;
        }

        // FEEDBACK: per-group happiness histogram (Happy/Fine/Unhappy) for
        // outcomes dated $date on native posts — one grouped query covering all
        // three buckets (replaces the three per-group distinct counts).
        $feedback = [];
        foreach (
            DB::table('messages_outcomes')
                ->join('messages', 'messages_outcomes.msgid', '=', 'messages.id')
                ->join('messages_groups', function ($j) {
                    $j->on('messages_groups.msgid', '=', 'messages.id')
                        ->where('messages_groups.rippled_in', '=', 0); // native posts only
                })
                ->where('messages_outcomes.timestamp', '>=', $date)
                ->where('messages_outcomes.timestamp', '<', $next)
                ->whereIn('messages_outcomes.happiness', [
                    self::TYPE_FEEDBACK_HAPPY,
                    self::TYPE_FEEDBACK_FINE,
                    self::TYPE_FEEDBACK_UNHAPPY,
                ])
                ->groupBy('messages_groups.groupid', 'messages_outcomes.happiness')
                ->selectRaw('messages_groups.groupid AS gid, messages_outcomes.happiness AS happiness, COUNT(DISTINCT messages_outcomes.msgid) AS cnt')
                ->get() as $row
        ) {
            $feedback[(int) $row->gid][$row->happiness] = (int) $row->cnt;
        }

        // OUR_POSTING_BREAKDOWN: per-member ourPostingStatus histogram
        // (point-in-time, not date-windowed). A null status maps to the ""
        // key exactly as the original per-group $statusMap did.
        $ourPosting = [];
        foreach (
            DB::table('memberships')
                ->groupBy('groupid', 'ourPostingStatus')
                ->selectRaw('groupid AS gid, ourPostingStatus, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $ourPosting[(int) $row->gid][$row->ourPostingStatus] = (int) $row->cnt;
        }

        // REPLIES non-bulk: "Interested" chat messages referring to approved native
        // non-bulk posts on $date, per group.
        $replies = [];
        foreach (
            DB::table('chat_messages')
                ->join('messages_groups', 'chat_messages.refmsgid', '=', 'messages_groups.msgid')
                ->where('chat_messages.date', '>=', $date)
                ->where('chat_messages.date', '<', $next)
                ->where('chat_messages.type', ChatMessage::TYPE_INTERESTED)
                ->where('messages_groups.rippled_in', 0) // native posts only
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('messages_bulk_items')
                        ->whereColumn('messages_bulk_items.msgid', 'chat_messages.refmsgid');
                })
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $replies[(int) $row->gid] = (int) $row->cnt;
        }

        // REPLIES bulk part 1: structured interest rows created on $date — one row
        // per (item, user) pair counts as one reply signal.
        foreach (
            DB::table('messages_bulk_items_interest')
                ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_bulk_items_interest.msgid')
                ->where('messages_groups.rippled_in', 0)
                ->where('messages_bulk_items_interest.created_at', '>=', $date)
                ->where('messages_bulk_items_interest.created_at', '<', $next)
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $gid = (int) $row->gid;
            $replies[$gid] = ($replies[$gid] ?? 0) + (int) $row->cnt;
        }

        // REPLIES bulk part 2: free-text Interested chat messages for bulk msgids
        // where the sender has no interest row — they replied outside the structured
        // flow and would otherwise be missed entirely.
        foreach (
            DB::table('chat_messages')
                ->join('messages_groups', 'chat_messages.refmsgid', '=', 'messages_groups.msgid')
                ->where('chat_messages.date', '>=', $date)
                ->where('chat_messages.date', '<', $next)
                ->where('chat_messages.type', ChatMessage::TYPE_INTERESTED)
                ->where('messages_groups.rippled_in', 0)
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
                ->groupBy('messages_groups.groupid')
                ->selectRaw('messages_groups.groupid AS gid, COUNT(*) AS cnt')
                ->get() as $row
        ) {
            $gid = (int) $row->gid;
            $replies[$gid] = ($replies[$gid] ?? 0) + (int) $row->cnt;
        }

        // WEIGHT non-bulk: estimated kg moved on $date per group, using the same
        // grouped subquery as regenerateWeightForRange() — DISTINCT
        // (msgid, groupid, eff_weight) with items.weight when known/non-zero,
        // else the popularity-weighted population mean. Equivalent to the old
        // per-group distinct()+foreach. Bulk-offer messages are excluded here.
        $weight = [];
        foreach (
            DB::select(
                'SELECT sub.groupid AS gid, ROUND(SUM(sub.eff_weight)) AS total_weight '
                . 'FROM ('
                . '  SELECT DISTINCT mo.msgid, mg.groupid, '
                . '    COALESCE(NULLIF(i.weight, 0), ?) AS eff_weight '
                . '  FROM messages_outcomes mo '
                . '  INNER JOIN messages_groups mg ON mg.msgid = mo.msgid AND mg.rippled_in = 0 '
                . '  INNER JOIN messages_items mi ON mi.msgid = mo.msgid '
                . '  LEFT JOIN items i ON i.id = mi.itemid '
                . '  WHERE mo.timestamp >= ? AND mo.timestamp < ? '
                . '    AND mo.outcome IN (?, ?)'
                . '    AND NOT EXISTS (SELECT 1 FROM messages_bulk_items bxi WHERE bxi.msgid = mo.msgid)'
                . ') sub '
                . 'GROUP BY sub.groupid',
                [$avg, $date, $next, Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED]
            ) as $row
        ) {
            $weight[(int) $row->gid] = (int) $row->total_weight;
        }

        // WEIGHT bulk flip: catalogue items marked available=0 on $date, weighted by
        // items.weight matched by name (not itemid), multiplied by remaining quantity.
        foreach (
            DB::select(
                'SELECT mg.groupid AS gid, '
                . '  ROUND(SUM(COALESCE(NULLIF(i.weight, 0), ?) * bi.quantity)) AS bulk_weight '
                . 'FROM messages_bulk_items bi '
                . 'INNER JOIN messages_groups mg ON mg.msgid = bi.msgid AND mg.rippled_in = 0 '
                . 'LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci '
                . 'WHERE bi.available = 0 '
                . '  AND bi.updated_at >= ? AND bi.updated_at < ? '
                . 'GROUP BY mg.groupid',
                [$avg, $date, $next]
            ) as $row
        ) {
            $gid = (int) $row->gid;
            $bw = (int) $row->bulk_weight;
            if ($bw !== 0) {
                $weight[$gid] = ($weight[$gid] ?? 0) + $bw;
            }
        }

        // WEIGHT collected: in-app collections attributed on updated_at day, weighted by
        // items.weight matched via the parent bulk item's name, times interest-row quantity.
        foreach (
            DB::select(
                'SELECT mg.groupid AS gid, '
                . '  ROUND(SUM(COALESCE(NULLIF(i.weight, 0), ?) * mbi.quantity)) AS coll_weight '
                . 'FROM messages_bulk_items_interest mbi '
                . 'INNER JOIN messages_bulk_items bi ON bi.id = mbi.bulkitemid '
                . 'INNER JOIN messages_groups mg ON mg.msgid = mbi.msgid AND mg.rippled_in = 0 '
                . 'LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci '
                . 'WHERE mbi.state = ? '
                . '  AND mbi.updated_at >= ? AND mbi.updated_at < ? '
                . 'GROUP BY mg.groupid',
                [$avg, 'Collected', $date, $next]
            ) as $row
        ) {
            $gid = (int) $row->gid;
            $cw = (int) $row->coll_weight;
            if ($cw !== 0) {
                $weight[$gid] = ($weight[$gid] ?? 0) + $cw;
            }
        }

        return [
            'avgWeight' => $avg,
            'searchCounts' => $searchCounts,
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
     * Generate all stat types for a single group/date.
     *
     * Mirrors V1 Stats::generate($date) with all 16 type branches plus the
     * derived ACTIVITY (sum of APPROVED_MESSAGE_COUNT + REPLIES). REPLACE
     * semantics so re-running the same date overwrites prior rows — safe for
     * backfills.
     *
     * Returns the number of `stats` rows touched (for accounting/dry-run).
     *
     * $context may be passed by generateForAllGroups() to share work that's
     * the same for every group on this date (avg weight, search-history
     * tally). When null we compute them locally — useful for ad-hoc calls
     * and tests.
     */
    public function generate(int $groupId, string $date, bool $dryRun = false, ?array $context = null): int
    {
        $context ??= $this->buildDailyContext($date);
        $rows = 0;

        // Every per-group value below is precomputed once per date in
        // buildDailyContext() (one grouped query per stat, bucketed by
        // groupid) with identical WHERE/DISTINCT semantics to the original
        // per-group queries. generate() therefore issues NO per-group DB
        // queries — only context lookups + writeCount/writeBreakdown.

        // OUTCOMES: distinct messages on this group with a Taken/Received outcome dated $date.
        $rows += $this->writeCount($date, $groupId, self::TYPE_OUTCOMES, $context['outcomes'][$groupId] ?? 0, $dryRun);

        // APPROVED_MESSAGE_COUNT: distinct approved messages whose arrival is $date. Also feeds ACTIVITY.
        $approvedMessages = $context['approvedMessages'][$groupId] ?? 0;
        $rows += $this->writeCount($date, $groupId, self::TYPE_APPROVED_MESSAGE_COUNT, $approvedMessages, $dryRun);

        // APPROVED_MEMBER_COUNT: cumulative approved members as of $date.
        $rows += $this->writeCount($date, $groupId, self::TYPE_APPROVED_MEMBER_COUNT, $context['approvedMembers'][$groupId] ?? 0, $dryRun);

        // SPAM_MESSAGE_COUNT: ClassifiedSpam log entries for messages on this group on $date.
        $rows += $this->writeCount($date, $groupId, self::TYPE_SPAM_MESSAGE_COUNT, $context['spamMessages'][$groupId] ?? 0, $dryRun);

        // SPAM_MEMBER_COUNT: known spammers who left this group on $date.
        $rows += $this->writeCount($date, $groupId, self::TYPE_SPAM_MEMBER_COUNT, $context['spamMembers'][$groupId] ?? 0, $dryRun);

        // SUPPORTQUERIES_COUNT: User2Mod chats created on $date for this group.
        $rows += $this->writeCount($date, $groupId, self::TYPE_SUPPORTQUERIES_COUNT, $context['supportQueries'][$groupId] ?? 0, $dryRun);

        // Feedback breakdown (happy/fine/unhappy) — read each bucket from the
        // precomputed per-group happiness histogram.
        foreach ([
            self::TYPE_FEEDBACK_HAPPY,
            self::TYPE_FEEDBACK_FINE,
            self::TYPE_FEEDBACK_UNHAPPY,
        ] as $happiness) {
            $rows += $this->writeCount($date, $groupId, $happiness, $context['feedback'][$groupId][$happiness] ?? 0, $dryRun);
        }

        // POST_METHOD_BREAKDOWN + MESSAGE_BREAKDOWN: sourceheader / message-type
        // histograms for approved native messages in the 30-day window.
        $rows += $this->writeBreakdown($date, $groupId, self::TYPE_POST_METHOD_BREAKDOWN, $context['postMethod'][$groupId] ?? [], $dryRun);
        $rows += $this->writeBreakdown($date, $groupId, self::TYPE_MESSAGE_BREAKDOWN, $context['messageTypes'][$groupId] ?? [], $dryRun);

        // OUR_POSTING_BREAKDOWN: per-member ourPostingStatus histogram (point-in-time, not date-windowed).
        $rows += $this->writeBreakdown($date, $groupId, self::TYPE_OUR_POSTING_BREAKDOWN, $context['ourPosting'][$groupId] ?? [], $dryRun);

        // SEARCHES: per-group tally pre-computed once per day from search_history.
        $rows += $this->writeCount($date, $groupId, self::TYPE_SEARCHES, $context['searchCounts'][$groupId] ?? 0, $dryRun);

        // REPLIES: "Interested" chat messages referring to approved posts on this group on $date. Also feeds ACTIVITY.
        $replies = $context['replies'][$groupId] ?? 0;
        $rows += $this->writeCount($date, $groupId, self::TYPE_REPLIES, $replies, $dryRun);

        // WEIGHT: estimated kg of items moved on $date. Uses items.weight when known,
        // otherwise the popularity-weighted population mean (V1 logic).
        $rows += $this->writeCount($date, $groupId, self::TYPE_WEIGHT, $context['weight'][$groupId] ?? 0, $dryRun);

        // ACTIVE_USERS: distinct members of this group active in the 30 days ending tomorrow-of-$date.
        $rows += $this->writeCount($date, $groupId, self::TYPE_ACTIVE_USERS, $context['activeUsers'][$groupId] ?? 0, $dryRun);

        // ACTIVITY: rolled-up "things happened today" — approved-messages + replies (V1 formula).
        $rows += $this->writeCount($date, $groupId, self::TYPE_ACTIVITY, $approvedMessages + $replies, $dryRun);

        return $rows;
    }

    /**
     * Fast Weight-only regeneration across a date range.
     *
     * The full generate() pipeline runs 17 type-specific aggregations per
     * (group, date) — for a 100-day × 500-group backfill that's ~850k
     * queries. Only the WEIGHT type depends on messages_items (the table
     * the cutover backfill repaired), so the other 16 are pure waste when
     * the goal is just to recover the Weight figures.
     *
     * This method computes Weight for every group on a date in ONE
     * grouped query and writes the results via a single multi-row
     * REPLACE — two statements per date, vs ~600 statements per date
     * for the full path. avgWeight is hoisted across the whole range
     * (it's a function of the items table, not the date).
     *
     * CO2 and reuse-value are NOT stored — they're pure functions of
     * Weight applied at read time by misc/ReuseBenefit.php, so fixing
     * Weight here implicitly fixes them too.
     *
     * @return array{datesProcessed: int, rowsWritten: int}
     */
    public function regenerateWeightForRange(string $from, string $to, bool $dryRun = false): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        $avg = (float) (DB::table('items')
            ->whereNotNull('weight')
            ->where('weight', '!=', 0)
            ->selectRaw('SUM(popularity * weight) / SUM(popularity) AS average')
            ->value('average') ?? 0);

        $datesProcessed = 0;
        $rowsWritten = 0;

        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $next = $d->addDay()->toDateString();

            // Range query on timestamp (uses timestamp_2 composite index)
            // beats the original DATE(timestamp)=? predicate which forced
            // a scan. DISTINCT inside the subquery preserves V1 behavior:
            // one (msgid, eff_weight) tuple counts once, but a msgid linked
            // to multiple items with different weights contributes all of
            // them — same shape as the PHP `distinct()` + foreach the old
            // path had. Bulk-offer messages are excluded and handled below.
            $rows = DB::select(
                'SELECT sub.groupid, ROUND(SUM(sub.eff_weight)) AS total_weight '
                . 'FROM ('
                . '  SELECT DISTINCT mo.msgid, mg.groupid, '
                . '    COALESCE(NULLIF(i.weight, 0), ?) AS eff_weight '
                . '  FROM messages_outcomes mo '
                . '  INNER JOIN messages_groups mg ON mg.msgid = mo.msgid AND mg.rippled_in = 0 '
                . '  INNER JOIN messages_items mi ON mi.msgid = mo.msgid '
                . '  LEFT JOIN items i ON i.id = mi.itemid '
                . '  WHERE mo.timestamp >= ? AND mo.timestamp < ? '
                . '    AND mo.outcome IN (?, ?)'
                . '    AND NOT EXISTS (SELECT 1 FROM messages_bulk_items bxi WHERE bxi.msgid = mo.msgid)'
                . ') sub '
                . 'GROUP BY sub.groupid',
                [$avg, $date, $next, Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED]
            );

            // Merge non-bulk results into a groupid-keyed array.
            $weightByGroup = [];
            foreach ($rows as $row) {
                $w = (int) $row->total_weight;
                if ($w !== 0) {
                    $weightByGroup[(int) $row->groupid] = $w;
                }
            }

            // WEIGHT bulk flip arm: catalogue items marked available=0 on this date,
            // weighted by items.weight matched by name, multiplied by remaining quantity.
            foreach (
                DB::select(
                    'SELECT mg.groupid, '
                    . '  ROUND(SUM(COALESCE(NULLIF(i.weight, 0), ?) * bi.quantity)) AS bulk_weight '
                    . 'FROM messages_bulk_items bi '
                    . 'INNER JOIN messages_groups mg ON mg.msgid = bi.msgid AND mg.rippled_in = 0 '
                    . 'LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci '
                    . 'WHERE bi.available = 0 '
                    . '  AND bi.updated_at >= ? AND bi.updated_at < ? '
                    . 'GROUP BY mg.groupid',
                    [$avg, $date, $next]
                ) as $bulkRow
            ) {
                $gid = (int) $bulkRow->groupid;
                $bw = (int) $bulkRow->bulk_weight;
                if ($bw !== 0) {
                    $weightByGroup[$gid] = ($weightByGroup[$gid] ?? 0) + $bw;
                }
            }

            // WEIGHT collected arm: in-app collections attributed on updated_at day.
            foreach (
                DB::select(
                    'SELECT mg.groupid, '
                    . '  ROUND(SUM(COALESCE(NULLIF(i.weight, 0), ?) * mbi.quantity)) AS coll_weight '
                    . 'FROM messages_bulk_items_interest mbi '
                    . 'INNER JOIN messages_bulk_items bi ON bi.id = mbi.bulkitemid '
                    . 'INNER JOIN messages_groups mg ON mg.msgid = mbi.msgid AND mg.rippled_in = 0 '
                    . 'LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci '
                    . 'WHERE mbi.state = ? '
                    . '  AND mbi.updated_at >= ? AND mbi.updated_at < ? '
                    . 'GROUP BY mg.groupid',
                    [$avg, 'Collected', $date, $next]
                ) as $collRow
            ) {
                $gid = (int) $collRow->groupid;
                $cw = (int) $collRow->coll_weight;
                if ($cw !== 0) {
                    $weightByGroup[$gid] = ($weightByGroup[$gid] ?? 0) + $cw;
                }
            }

            $datesProcessed++;

            if ($dryRun) {
                $rowsWritten += count($weightByGroup);
                continue;
            }

            $placeholders = [];
            $values = [];
            foreach ($weightByGroup as $gid => $w) {
                $placeholders[] = '(?, ?, ?, ?)';
                array_push($values, $date, $gid, self::TYPE_WEIGHT, $w);
            }

            if (!empty($placeholders)) {
                DB::statement(
                    'REPLACE INTO stats (date, groupid, type, count) VALUES '
                    . implode(', ', $placeholders),
                    $values
                );
                $rowsWritten += count($placeholders);
            }
        }

        return ['datesProcessed' => $datesProcessed, 'rowsWritten' => $rowsWritten];
    }

    /**
     * REPLACE one count row. V1 skipped writes when $val was falsy
     * (setCount on line 45 of Stats.php). Preserve that — keeps the table
     * sparse and avoids storing a row for every (group, type, date) tuple
     * with a 0 count.
     */
    private function writeCount(string $date, int $groupId, string $type, int $val, bool $dryRun): int
    {
        if ($val === 0) {
            return 0;
        }
        if ($dryRun) {
            return 1;
        }

        DB::statement(
            'REPLACE INTO stats (date, groupid, type, count) VALUES (?, ?, ?, ?)',
            [$date, $groupId, $type, $val]
        );

        return 1;
    }

    /**
     * REPLACE one breakdown row. V1's setBreakdown wrote unconditionally
     * (no null-skip), so do the same — '[]' or '{}' is meaningful to
     * downstream consumers.
     */
    private function writeBreakdown(string $date, int $groupId, string $type, array $map, bool $dryRun): int
    {
        if ($dryRun) {
            return 1;
        }

        DB::statement(
            'REPLACE INTO stats (date, groupid, type, breakdown) VALUES (?, ?, ?, ?)',
            [$date, $groupId, $type, json_encode($map, JSON_UNESCAPED_UNICODE)]
        );

        return 1;
    }
}
