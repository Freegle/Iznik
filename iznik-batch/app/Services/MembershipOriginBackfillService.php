<?php

namespace App\Services;

use App\Models\Membership;
use App\Services\Ripple\RippleEngagementService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill memberships.added_origin and memberships_history.added_origin for rows written before
 * every insert recorded it (they hold the column default, unknown). The origin is read back from
 * the Group/Joined log of the join that created the row. Only rows still unknown are touched, so
 * a re-run picks up where the last one stopped and changes nothing it has already set.
 *
 * A membership takes the most recent Group/Joined log for its (user, group), ignoring the
 * "became ordinary" rows, which record a change of state and not a join. The row is deleted
 * when a member leaves, so the latest join is the one that created it. "Any Rippled log
 * exists" would be wrong: it misreads a member rippled in, who left and later joined by hand.
 * A membership with rippled = 1 is rippled whatever the logs say, since only rippling sets it.
 *
 * A history row records one join, so it takes the join log written with it: the one for the
 * same (user, group) nearest its added time, within HISTORY_MATCH_SECONDS.
 *
 * Joins with no reason in the log (before 2023-07-26 nothing recorded one, and the post joins
 * still don't) count as posted when a post of theirs reached the group around the join, and
 * otherwise stay unknown: the backfill claims only what it can show. Unknown here is
 * deliberate, so unlike Membership::addedOriginForInsert this reports nothing to Sentry and
 * counts it in the summary instead.
 *
 * The latest join per (user, group) is gathered first into a temporary table, from one pass
 * over the Group/Joined logs through the (type, subtype) index, keeping the text, actor and
 * time the classification needs so no log is read a second time. Looking each membership up
 * through logs.user instead reads every log the member has, which on production took hours
 * for 180,000 rows.
 *
 * Whether a post of theirs reached the group around a join is asked once per chunk of rows, in
 * one query for the chunk's users, and not once per row.
 */
class MembershipOriginBackfillService
{
    // A join log and the history row written with it fall in the same request. The margin
    // allows for a log insert held up by a lock wait (innodb_lock_wait_timeout is 50s).
    public const HISTORY_MATCH_SECONDS = 60;

    // A post of theirs on the group arriving this close to an unexplained join means they
    // joined to post.
    public const POST_BEFORE_JOIN_MINUTES = 5;
    public const POST_AFTER_JOIN_MINUTES = 30;

    // A latest join logged this long after the membership was added was most likely carried
    // over from another account by a merge, and is reported.
    public const JOIN_AFTER_ADDED_HOURS = 24;

    private const LATEST_JOINS = 'tmp_backfill_origin_latest_joins';

    private const LOG_SCAN_CHUNK = 10000;

    // History rows whose added times are further apart than this get their logs read in
    // separate windows, so a row with an outlying date doesn't widen the read for the rest.
    private const HISTORY_WINDOW_GAP_SECONDS = 600;

    private const SAMPLE_SIZE = 10;

    // Group/Joined log texts that need more than a lookup.
    public const JOIN_TEXT_RIPPLED = 'Rippled';
    public const JOIN_TEXT_MANUAL = 'Manual';
    public const JOIN_TEXT_AUTO = 'Auto';

    // Join log texts that say how the row was made, whoever wrote them.
    private const TEXT_ORIGINS = [
        self::JOIN_TEXT_RIPPLED => Membership::ADDED_ORIGIN_RIPPLED,
        GroupPostIngestionService::JOIN_LOG_CHOSEN => Membership::ADDED_ORIGIN_PARTNER,
        'Subscribed' => Membership::ADDED_ORIGIN_SUBSCRIBED,
        'Joined to reply to a post' => Membership::ADDED_ORIGIN_REPLIED,
        'LoveJunk user joining to reply' => Membership::ADDED_ORIGIN_REPLIED,
    ];

    public const UNKNOWN_NO_JOIN_LOG = 'no join log';
    public const UNKNOWN_NO_ACTOR = 'system join with no reason';
    public const UNKNOWN_NO_POST = 'own join with no reason and no post near it';
    public const UNKNOWN_TEXT = 'own join with an unrecognised reason';

    // Disagreements reported in the summary. None changes what is written.
    public const CHECK_FLAG_WITHOUT_LOG = 'rippled = 1 but the latest join is not Rippled';
    public const CHECK_LOG_WITHOUT_HISTORY = 'latest join Rippled but no history row has rippled = 1';
    public const CHECK_HISTORY_WITHOUT_LOG = 'latest history row has rippled = 1 but the latest join is not Rippled';
    public const CHECK_JOIN_AFTER_ADDED = 'latest join logged over a day after the membership was added';

    private array $stats;

    /**
     * @param  callable(string):void|null  $progress  told what each pass has reached
     * @return array{
     *     memberships: array<string,int>, memberships_unknown: array<string,int>,
     *     history: array<string,int>, history_unknown: array<string,int>,
     *     checks: array<string,int>, samples: array<string,int[]>, unrecognised_texts: array<string,int>
     * }
     */
    public function backfill(?int $userId, int $chunk, bool $send, ?callable $progress = null): array
    {
        $this->stats = [
            'memberships' => [],
            'memberships_unknown' => [],
            'history' => [],
            'history_unknown' => [],
            'checks' => [],
            'samples' => [],
            'unrecognised_texts' => [],
        ];
        $progress ??= static fn () => null;

        $this->collectLatestJoins($userId, $progress);
        try {
            $this->backfillMemberships($userId, $chunk, $send, $progress);
        } finally {
            // keep-raw: Schema::dropIfExists() drops a permanent table of the same name too.
            DB::statement('DROP TEMPORARY TABLE IF EXISTS '.self::LATEST_JOINS);
        }
        $this->backfillHistory($userId, $chunk, $send, $progress);

        return $this->stats;
    }

    /**
     * The latest Group/Joined log for each (user, group), into a temporary table. Plain reads
     * of logs take no locks, so this doesn't hold up the live log writes the way an
     * INSERT ... SELECT from logs would.
     */
    private function collectLatestJoins(?int $userId, callable $progress): void
    {
        // keep-raw: Schema::dropIfExists() drops a permanent table of the same name too.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS '.self::LATEST_JOINS);
        Schema::create(self::LATEST_JOINS, function (Blueprint $table) {
            $table->temporary();
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('groupid');
            $table->unsignedBigInteger('logid');
            $table->mediumText('text')->nullable();
            $table->unsignedBigInteger('byuser')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->primary(['userid', 'groupid']);
        });

        $after = 0;
        $scanned = 0;
        do {
            $logs = $this->joinLogs()
                // One user's logs are few and quickest through logs.user. Every user's are
                // quickest in id order through (type, subtype), which the optimiser doesn't
                // always pick for ORDER BY id.
                ->when($userId !== null,
                    fn ($q) => $q->where('user', $userId),
                    fn ($q) => $q->forceIndex('type'))
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::LOG_SCAN_CHUNK)
                ->get(['id', 'user', 'groupid', 'text', 'byuser', 'timestamp']);

            // Ascending ids, so the last log seen for a pair is its latest.
            $latest = [];
            foreach ($logs as $log) {
                $latest[$log->user.':'.$log->groupid] = [
                    'userid' => $log->user, 'groupid' => $log->groupid, 'logid' => $log->id,
                    'text' => $log->text, 'byuser' => $log->byuser, 'timestamp' => $log->timestamp,
                ];
            }
            if ($latest) {
                DB::table(self::LATEST_JOINS)->upsert(array_values($latest), ['userid', 'groupid'], ['logid', 'text', 'byuser', 'timestamp']);
            }

            $after = $logs->last()->id ?? $after;
            $scanned += $logs->count();
            $progress("Read {$scanned} join logs");
        } while ($logs->count() === self::LOG_SCAN_CHUNK);
    }

    private function backfillMemberships(?int $userId, int $chunk, bool $send, callable $progress): void
    {
        $after = 0;
        $seen = 0;
        do {
            // The temporary table exists only on the connection that made it, which is the write
            // connection: a read would go to the read host and not find it.
            $rows = DB::table('memberships as m')
                ->leftJoin(self::LATEST_JOINS.' as j', function ($join) {
                    $join->on('j.userid', '=', 'm.userid')->on('j.groupid', '=', 'm.groupid');
                })
                ->where('m.added_origin', Membership::ADDED_ORIGIN_UNKNOWN)
                ->where('m.id', '>', $after)
                ->when($userId !== null, fn ($q) => $q->where('m.userid', $userId))
                ->orderBy('m.id')
                ->limit($chunk)
                ->useWritePdo()
                ->get([
                    'm.id', 'm.userid', 'm.groupid', 'm.rippled', 'm.added',
                    'j.logid', 'j.text', 'j.byuser', 'j.timestamp',
                ]);

            $historyRippled = $this->historyRippled($rows);
            $joins = [];
            foreach ($rows as $row) {
                if (!$row->rippled && $row->logid !== null) {
                    $joins[$row->id] = $row;
                }
            }
            $posted = $this->postedNearJoins($rows, $joins);
            $ids = [];
            foreach ($rows as $row) {
                $this->checkMembership($row,
                    $historyRippled[$row->userid.':'.$row->groupid] ?? ['any' => false, 'latest' => false]);

                if ($row->rippled) {
                    [$origin, $reason] = [Membership::ADDED_ORIGIN_RIPPLED, null];
                } elseif ($row->logid === null) {
                    [$origin, $reason] = [Membership::ADDED_ORIGIN_UNKNOWN, self::UNKNOWN_NO_JOIN_LOG];
                } else {
                    [$origin, $reason] = $this->originFromJoin($row->text, $row->byuser, $row->userid, isset($posted[$row->id]));
                }

                $this->count('memberships', $origin, $reason);
                $ids[$origin][] = $row->id;
            }

            if ($send) {
                $this->write('memberships', $ids);
            }

            $after = $rows->last()->id ?? $after;
            $seen += $rows->count();
            $progress("Classified {$seen} memberships");
        } while ($rows->count() === $chunk);
    }

    /**
     * Count the ways this membership's flag, latest join and history disagree.
     *
     * @param  array{any:bool,latest:bool}  $history
     */
    private function checkMembership(object $row, array $history): void
    {
        $joinIsRippled = $row->logid !== null && $row->text === self::JOIN_TEXT_RIPPLED;

        if ($row->rippled && !$joinIsRippled) {
            $this->check(self::CHECK_FLAG_WITHOUT_LOG, $row->id);
        }
        if ($joinIsRippled && !$history['any']) {
            $this->check(self::CHECK_LOG_WITHOUT_HISTORY, $row->id);
        }
        if (!$row->rippled && !$joinIsRippled && $history['latest']) {
            $this->check(self::CHECK_HISTORY_WITHOUT_LOG, $row->id);
        }
        if ($row->logid !== null && $row->added !== null
            && Carbon::parse($row->timestamp)->gt(Carbon::parse($row->added)->addHours(self::JOIN_AFTER_ADDED_HOURS))) {
            $this->check(self::CHECK_JOIN_AFTER_ADDED, $row->id);
        }
    }

    private function backfillHistory(?int $userId, int $chunk, bool $send, callable $progress): void
    {
        $after = 0;
        $seen = 0;
        do {
            $rows = DB::table('memberships_history')
                ->where('added_origin', Membership::ADDED_ORIGIN_UNKNOWN)
                ->where('id', '>', $after)
                ->when($userId !== null, fn ($q) => $q->where('userid', $userId))
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'userid', 'groupid', 'rippled', 'added']);

            $logsByPair = $this->joinLogsAround($rows);
            $joins = [];
            foreach ($rows as $row) {
                if (!$row->rippled
                    && $log = $this->nearestLog($logsByPair[$row->userid.':'.$row->groupid] ?? [], $row->added)) {
                    $joins[$row->id] = $log;
                }
            }
            $posted = $this->postedNearJoins($rows, $joins);
            $ids = [];
            foreach ($rows as $row) {
                if ($row->rippled) {
                    [$origin, $reason] = [Membership::ADDED_ORIGIN_RIPPLED, null];
                } elseif (!isset($joins[$row->id])) {
                    [$origin, $reason] = [Membership::ADDED_ORIGIN_UNKNOWN, self::UNKNOWN_NO_JOIN_LOG];
                } else {
                    $log = $joins[$row->id];
                    [$origin, $reason] = $this->originFromJoin($log->text, $log->byuser, $row->userid, isset($posted[$row->id]));
                }

                $this->count('history', $origin, $reason);
                $ids[$origin][] = $row->id;
            }

            if ($send) {
                $this->write('memberships_history', $ids);
            }

            $after = $rows->last()->id ?? $after;
            $seen += $rows->count();
            $progress("Classified {$seen} history rows");
        } while ($rows->count() === $chunk);
    }

    /**
     * The origin a join log records, and why when that is unknown.
     *
     * @param  bool  $posted  whether a post of theirs reached the group around the join; only
     *                        read when needsPostCheck() is true
     * @return array{0:string,1:?string}
     */
    private function originFromJoin(?string $text, ?int $byuser, int $userid, bool $posted): array
    {
        if ($text !== null && isset(self::TEXT_ORIGINS[$text])) {
            return [self::TEXT_ORIGINS[$text], null];
        }

        // byuser 0 is the legacy code's placeholder for the system, not an account.
        if (!$byuser) {
            return [Membership::ADDED_ORIGIN_UNKNOWN, self::UNKNOWN_NO_ACTOR];
        }

        // Somebody else added them, whatever the text says: a moderator, or support.
        if ($byuser !== $userid) {
            return [Membership::ADDED_ORIGIN_MODERATOR, null];
        }

        if ($text === self::JOIN_TEXT_MANUAL) {
            return [Membership::ADDED_ORIGIN_MANUAL, null];
        }

        // Auto was sent by the join to reply and by the old join to post; NULL text is the post
        // joins, website signup and, before 2023-07-26, every join.
        if ($this->needsPostCheck($text, $byuser, $userid)) {
            if ($posted) {
                return [Membership::ADDED_ORIGIN_POSTED, null];
            }

            return $text === self::JOIN_TEXT_AUTO
                ? [Membership::ADDED_ORIGIN_REPLIED, null]
                : [Membership::ADDED_ORIGIN_UNKNOWN, self::UNKNOWN_NO_POST];
        }

        if (count($this->stats['unrecognised_texts']) < 50 || isset($this->stats['unrecognised_texts'][$text])) {
            $this->stats['unrecognised_texts'][$text] = ($this->stats['unrecognised_texts'][$text] ?? 0) + 1;
        }

        return [Membership::ADDED_ORIGIN_UNKNOWN, self::UNKNOWN_TEXT];
    }

    /**
     * Whether originFromJoin() reaches the question of a post near the join: their own join, with
     * no text that says how it was made, or Auto.
     */
    private function needsPostCheck(?string $text, ?int $byuser, int $userid): bool
    {
        return $byuser && $byuser === $userid
            && ($text === self::JOIN_TEXT_AUTO || $text === null || $text === '');
    }

    /**
     * Which of $rows had a post of theirs reach the group around their join, as a set of row ids.
     * One query for the chunk: every post by the chunk's users on the chunk's groups within the
     * span of join times, matched to each row in memory.
     *
     * @param  Collection  $rows
     * @param  array<int,object>  $joins  row id => the join log (text, byuser, timestamp) it takes
     * @return array<int,true>
     */
    private function postedNearJoins(Collection $rows, array $joins): array
    {
        $probes = [];
        foreach ($rows as $row) {
            $join = $joins[$row->id] ?? null;
            if ($join && $this->needsPostCheck($join->text, $join->byuser, $row->userid)) {
                $probes[$row->id] = [$row->userid, $row->groupid, Carbon::parse($join->timestamp)->getTimestamp()];
            }
        }
        if (!$probes) {
            return [];
        }

        $before = self::POST_BEFORE_JOIN_MINUTES * 60;
        $after = self::POST_AFTER_JOIN_MINUTES * 60;
        $times = array_column($probes, 2);
        $arrivals = [];
        $posts = DB::table('messages as p')
            ->join('messages_groups as pg', function ($join) {
                $join->on('pg.msgid', '=', 'p.id')->where('pg.rippled_in', 0);
            })
            ->whereIn('p.fromuser', array_unique(array_column($probes, 0)))
            ->whereIn('pg.groupid', array_unique(array_column($probes, 1)))
            ->whereBetween('p.arrival', [
                Carbon::createFromTimestamp(min($times) - $before),
                Carbon::createFromTimestamp(max($times) + $after),
            ])
            ->get(['p.fromuser', 'pg.groupid', 'p.arrival']);
        foreach ($posts as $post) {
            $arrivals[$post->fromuser.':'.$post->groupid][] = Carbon::parse($post->arrival)->getTimestamp();
        }

        $posted = [];
        foreach ($probes as $id => [$userid, $groupid, $at]) {
            foreach ($arrivals[$userid.':'.$groupid] ?? [] as $arrival) {
                if ($arrival >= $at - $before && $arrival <= $at + $after) {
                    $posted[$id] = true;
                    break;
                }
            }
        }

        return $posted;
    }

    /**
     * Group/Joined logs, without the "became ordinary" rows written to the same type.
     */
    private function joinLogs(): \Illuminate\Database\Query\Builder
    {
        return DB::table('logs')
            ->where('type', 'Group')
            ->where('subtype', 'Joined')
            ->whereNotNull('user')
            ->whereNotNull('groupid')
            ->where(function ($q) {
                $q->whereNull('text')->orWhere('text', 'not like', RippleEngagementService::LOG_PREFIX.'%');
            });
    }

    /**
     * For each (user, group) in $rows that has history rows: whether any of them has
     * rippled = 1, and whether the most recent one does.
     *
     * @return array<string,array{any:bool,latest:bool}>
     */
    private function historyRippled(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $wanted = $rows->mapWithKeys(fn ($r) => [$r->userid.':'.$r->groupid => true])->all();
        $result = [];
        $history = DB::table('memberships_history')
            ->whereIn('userid', $rows->pluck('userid')->unique()->values())
            ->orderBy('id')
            ->get(['userid', 'groupid', 'rippled']);
        foreach ($history as $h) {
            $key = $h->userid.':'.$h->groupid;
            if (isset($wanted[$key])) {
                $result[$key] = [
                    'any' => ($result[$key]['any'] ?? false) || $h->rippled,
                    'latest' => (bool) $h->rippled,
                ];
            }
        }

        return $result;
    }

    /**
     * The join logs written around the history rows in $rows, keyed by (user, group). The rows
     * come in id order, which is nearly time order, so most chunks read one short window of logs.
     *
     * @return array<string,object[]>
     */
    private function joinLogsAround(Collection $rows): array
    {
        $times = $rows->filter(fn ($r) => $r->added !== null)
            ->map(fn ($r) => Carbon::parse($r->added)->getTimestamp())
            ->sort()
            ->values();
        if ($times->isEmpty()) {
            return [];
        }

        $windows = [];
        $start = $end = $times->first();
        foreach ($times as $t) {
            if ($t - $end > self::HISTORY_WINDOW_GAP_SECONDS) {
                $windows[] = [$start, $end];
                $start = $t;
            }
            $end = $t;
        }
        $windows[] = [$start, $end];

        $userIds = $rows->pluck('userid')->unique()->values();
        $byPair = [];
        foreach ($windows as [$from, $to]) {
            // A window is short, so (timestamp, type, subtype) reads only its joins. Through
            // logs.user it would read every log the chunk's members have ever had.
            $logs = $this->joinLogs()
                ->forceIndex('timestamp_2')
                ->whereIn('user', $userIds)
                ->whereBetween('timestamp', [
                    Carbon::createFromTimestamp($from - self::HISTORY_MATCH_SECONDS),
                    Carbon::createFromTimestamp($to + self::HISTORY_MATCH_SECONDS),
                ])
                ->get(['user', 'groupid', 'text', 'byuser', 'timestamp']);
            foreach ($logs as $log) {
                $byPair[$log->user.':'.$log->groupid][] = $log;
            }
        }

        return $byPair;
    }

    private function nearestLog(array $logs, ?string $added): ?object
    {
        if ($added === null) {
            return null;
        }

        $at = Carbon::parse($added)->getTimestamp();
        $best = null;
        $bestGap = self::HISTORY_MATCH_SECONDS + 1;
        foreach ($logs as $log) {
            $gap = abs(Carbon::parse($log->timestamp)->getTimestamp() - $at);
            if ($gap < $bestGap) {
                $best = $log;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,int[]>  $idsByOrigin
     */
    private function write(string $table, array $idsByOrigin): void
    {
        foreach ($idsByOrigin as $origin => $ids) {
            if ($origin === Membership::ADDED_ORIGIN_UNKNOWN) {
                continue;
            }
            // Still unknown: anything set since the read was set by the live code, which knows.
            DB::table($table)
                ->whereIn('id', $ids)
                ->where('added_origin', Membership::ADDED_ORIGIN_UNKNOWN)
                ->update(['added_origin' => $origin]);
        }
    }

    private function count(string $table, string $origin, ?string $reason): void
    {
        $this->stats[$table][$origin] = ($this->stats[$table][$origin] ?? 0) + 1;
        if ($reason !== null) {
            $this->stats[$table.'_unknown'][$reason] = ($this->stats[$table.'_unknown'][$reason] ?? 0) + 1;
        }
    }

    private function check(string $name, int $membershipId): void
    {
        $this->stats['checks'][$name] = ($this->stats['checks'][$name] ?? 0) + 1;
        if (count($this->stats['samples'][$name] ?? []) < self::SAMPLE_SIZE) {
            $this->stats['samples'][$name][] = $membershipId;
        }
    }
}
