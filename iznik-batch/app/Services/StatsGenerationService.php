<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Membership;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates the daily `stats` table rows that V1's Stats::generate() wrote.
 *
 * V1 source: iznik-server/include/misc/Stats.php and scripts/cron/group_stats.php.
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
     * Build the per-day shared values: average item weight + search counts
     * keyed by groupid. Both are functions of the date alone, so we compute
     * them once and pass them into each per-group `generate()` call.
     *
     * @return array{avgWeight: float, searchCounts: array<int,int>}
     */
    private function buildDailyContext(string $date): array
    {
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

        return [
            'avgWeight' => $avg,
            'searchCounts' => $searchCounts,
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
        $activity = 0;

        // OUTCOMES: distinct messages on this group with a Taken/Received outcome dated $date.
        $count = (int) DB::table('messages_outcomes')
            ->join('messages_groups', 'messages_outcomes.msgid', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $groupId)
            ->where('messages_outcomes.timestamp', '>=', $date)
            ->whereRaw('DATE(messages_outcomes.timestamp) = ?', [$date])
            ->whereIn('messages_outcomes.outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED])
            ->distinct()
            ->count('messages_outcomes.msgid');
        $rows += $this->writeCount($date, $groupId, self::TYPE_OUTCOMES, $count, $dryRun);

        // APPROVED_MESSAGE_COUNT: distinct approved messages whose arrival is $date.
        $count = (int) DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $groupId)
            ->where('messages.arrival', '>=', $date)
            ->whereRaw('DATE(messages.arrival) = ?', [$date])
            ->where('messages_groups.collection', Membership::COLLECTION_APPROVED)
            ->distinct()
            ->count('messages_groups.msgid');
        $activity += $count;
        $rows += $this->writeCount($date, $groupId, self::TYPE_APPROVED_MESSAGE_COUNT, $count, $dryRun);

        // APPROVED_MEMBER_COUNT: cumulative approved members as of $date.
        $count = (int) DB::table('memberships')
            ->where('groupid', $groupId)
            ->whereRaw('DATE(added) <= ?', [$date])
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->count();
        $rows += $this->writeCount($date, $groupId, self::TYPE_APPROVED_MEMBER_COUNT, $count, $dryRun);

        // SPAM_MESSAGE_COUNT: ClassifiedSpam log entries for messages on this group on $date.
        $count = (int) DB::table('logs')
            ->where('timestamp', '>=', $date)
            ->whereRaw('DATE(timestamp) = ?', [$date])
            ->where('groupid', $groupId)
            ->where('type', 'Message')
            ->where('subtype', 'ClassifiedSpam')
            ->count();
        $rows += $this->writeCount($date, $groupId, self::TYPE_SPAM_MESSAGE_COUNT, $count, $dryRun);

        // SPAM_MEMBER_COUNT: known spammers who left this group on $date.
        $count = (int) DB::table('logs')
            ->join('spam_users', function ($j) {
                $j->on('logs.user', '=', 'spam_users.userid')
                    ->where('spam_users.collection', '=', 'Spammer');
            })
            ->where('logs.groupid', $groupId)
            ->where('logs.timestamp', '>=', $date)
            ->whereRaw('DATE(logs.timestamp) = ?', [$date])
            ->where('logs.type', 'Group')
            ->where('logs.subtype', 'Left')
            ->count();
        $rows += $this->writeCount($date, $groupId, self::TYPE_SPAM_MEMBER_COUNT, $count, $dryRun);

        // SUPPORTQUERIES_COUNT: User2Mod chats created on $date for this group.
        $count = (int) DB::table('chat_rooms')
            ->where('created', '>=', $date)
            ->whereRaw('DATE(created) = ?', [$date])
            ->where('groupid', $groupId)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->count();
        $rows += $this->writeCount($date, $groupId, self::TYPE_SUPPORTQUERIES_COUNT, $count, $dryRun);

        // Feedback breakdown (happy/fine/unhappy) — same query shape for each.
        foreach ([
            self::TYPE_FEEDBACK_HAPPY,
            self::TYPE_FEEDBACK_FINE,
            self::TYPE_FEEDBACK_UNHAPPY,
        ] as $happiness) {
            $count = (int) DB::table('messages_outcomes')
                ->join('messages', 'messages_outcomes.msgid', '=', 'messages.id')
                ->join('messages_groups', function ($j) use ($groupId) {
                    $j->on('messages_groups.msgid', '=', 'messages.id')
                        ->where('messages_groups.groupid', '=', $groupId);
                })
                ->where('messages_outcomes.timestamp', '>=', $date)
                ->whereRaw('DATE(messages_outcomes.timestamp) = ?', [$date])
                ->where('messages_outcomes.happiness', $happiness)
                ->distinct()
                ->count('messages_outcomes.msgid');
            $rows += $this->writeCount($date, $groupId, $happiness, $count, $dryRun);
        }

        // 30-day rolling window used by POST_METHOD_BREAKDOWN and MESSAGE_BREAKDOWN.
        $windowStart = date('Y-m-d', strtotime('30 days ago', strtotime($date)));
        $windowEnd = date('Y-m-d', strtotime('tomorrow', strtotime($date)));

        // POST_METHOD_BREAKDOWN: sourceheader histogram for approved messages in the 30-day window.
        $sources = DB::table('messages')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages.arrival', '>=', $windowStart)
            ->where('messages.arrival', '<', $windowEnd)
            ->where('messages_groups.groupid', $groupId)
            ->where('messages_groups.collection', 'Approved')
            ->whereNotNull('messages.sourceheader')
            ->groupBy('messages.sourceheader')
            ->selectRaw('messages.sourceheader AS source, COUNT(*) AS count')
            ->get();
        $srcMap = [];
        foreach ($sources as $row) {
            $srcMap[$row->source] = (int) $row->count;
        }
        $rows += $this->writeBreakdown($date, $groupId, self::TYPE_POST_METHOD_BREAKDOWN, $srcMap, $dryRun);

        // MESSAGE_BREAKDOWN: message-type histogram for approved messages in the 30-day window.
        $types = DB::table('messages')
            ->join('messages_groups', function ($j) {
                $j->on('messages.id', '=', 'messages_groups.msgid')
                    ->where('messages_groups.collection', '=', 'Approved');
            })
            ->where('messages.arrival', '>=', $windowStart)
            ->where('messages.arrival', '<', $windowEnd)
            ->where('messages_groups.groupid', $groupId)
            ->whereNotNull('messages.type')
            ->groupBy('messages.type')
            ->selectRaw('messages.type AS type, COUNT(*) AS count')
            ->get();
        $typeMap = [];
        foreach ($types as $row) {
            $typeMap[$row->type] = (int) $row->count;
        }
        $rows += $this->writeBreakdown($date, $groupId, self::TYPE_MESSAGE_BREAKDOWN, $typeMap, $dryRun);

        // OUR_POSTING_BREAKDOWN: per-member ourPostingStatus histogram (point-in-time, not date-windowed).
        $statuses = DB::table('memberships')
            ->where('groupid', $groupId)
            ->groupBy('ourPostingStatus')
            ->selectRaw('ourPostingStatus, COUNT(*) AS count')
            ->get();
        $statusMap = [];
        foreach ($statuses as $row) {
            $statusMap[$row->ourPostingStatus] = (int) $row->count;
        }
        $rows += $this->writeBreakdown($date, $groupId, self::TYPE_OUR_POSTING_BREAKDOWN, $statusMap, $dryRun);

        // SEARCHES: per-group tally pre-computed once per day from search_history.
        $rows += $this->writeCount(
            $date,
            $groupId,
            self::TYPE_SEARCHES,
            $context['searchCounts'][$groupId] ?? 0,
            $dryRun,
        );

        // REPLIES: "Interested" chat messages referring to approved posts on this group on $date.
        $replies = (int) DB::table('chat_messages')
            ->join('messages_groups', 'chat_messages.refmsgid', '=', 'messages_groups.msgid')
            ->where('chat_messages.date', '>=', $date)
            ->whereRaw('DATE(chat_messages.date) = ?', [$date])
            ->where('chat_messages.type', ChatMessage::TYPE_INTERESTED)
            ->where('messages_groups.groupid', $groupId)
            ->count();
        $activity += $replies;
        $rows += $this->writeCount($date, $groupId, self::TYPE_REPLIES, $replies, $dryRun);

        // WEIGHT: estimated kg of items moved on $date. Uses items.weight when known,
        // otherwise the popularity-weighted population mean (V1 logic).
        $avg = $context['avgWeight'];

        $weightRows = DB::table('messages_outcomes')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_outcomes.msgid')
            ->join('messages', 'messages.id', '=', 'messages_outcomes.msgid')
            ->join('messages_items', 'messages_outcomes.msgid', '=', 'messages_items.msgid')
            ->leftJoin('items', 'items.id', '=', 'messages_items.itemid')
            ->where('messages_outcomes.timestamp', '>=', $date)
            ->whereRaw('DATE(messages_outcomes.timestamp) = ?', [$date])
            ->where('messages_groups.groupid', $groupId)
            ->whereIn('messages_outcomes.outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED])
            ->distinct()
            ->select(['messages_outcomes.msgid', 'items.weight'])
            ->get();
        $weight = 0;
        foreach ($weightRows as $row) {
            $weight += $row->weight !== null && (float) $row->weight !== 0.0 ? (float) $row->weight : $avg;
        }
        $rows += $this->writeCount($date, $groupId, self::TYPE_WEIGHT, (int) round($weight), $dryRun);

        // ACTIVE_USERS: distinct members of this group active in the 30 days ending tomorrow-of-$date.
        $active = (int) DB::table('users_active')
            ->join('memberships', 'memberships.userid', '=', 'users_active.userid')
            ->where('users_active.timestamp', '>=', $windowStart)
            ->where('users_active.timestamp', '<', $windowEnd)
            ->where('memberships.groupid', $groupId)
            ->distinct()
            ->count('users_active.userid');
        $rows += $this->writeCount($date, $groupId, self::TYPE_ACTIVE_USERS, $active, $dryRun);

        // ACTIVITY: rolled-up "things happened today" — approved-messages + replies (V1 formula).
        $rows += $this->writeCount($date, $groupId, self::TYPE_ACTIVITY, $activity, $dryRun);

        return $rows;
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
