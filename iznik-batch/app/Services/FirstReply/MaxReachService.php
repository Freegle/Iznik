<?php

namespace App\Services\FirstReply;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\MaxReachCandidateIndex;
use App\Services\Ripple\ReachService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Will this post ever reach here?", as opposed to "does it reach here yet?".
 *
 * Rippling grows a post's reach over days. rippling_reach.polygon_cells is where
 * it has got to; the tick schedule cached alongside it already describes where it
 * is GOING, because the routing server computes the whole schedule up front.
 * Nothing used that, so every gate could only ask about the present.
 *
 * The difference matters in one specific place: a reply from someone the post has
 * not reached yet, but will. Holding that reply back does not protect local-first
 * ordering in any meaningful way, because the person is going to be allowed to
 * reply anyway - it just makes their reply late. On a post that already has
 * replies that trade is fine. On a post that has NONE it is actively harmful,
 * because a delayed first reply and no reply at all feel identical to the poster.
 *
 * The final-tick grid is copied into rippling_reach.max_polygon_cells by a
 * background pass rather than derived on demand, because some schedule entries
 * carry no inline WKT and have to be re-fetched from the routing server. A
 * containment test on a reply is not somewhere to discover that. Rows the pass
 * has not reached yet keep max_polygon_cells NULL, and every reader treats NULL
 * as "no wider reach known", which degrades exactly to today's behaviour.
 */
class MaxReachService
{
    private const SRID = 3857;

    /** Memoized: has the max_polygon_cells (raster-storage) migration run? */
    private static ?bool $cellsColumnExists = null;

    public function __construct(private ReachService $reach, private CellSetService $cellSets)
    {
    }

    /** Test-only: forget the memoized cells-column check. */
    public static function forgetCellsAvailability(): void
    {
        self::$cellsColumnExists = null;
    }

    /**
     * Has plans/2026-08-24-rippling-reach-raster-storage.md's column landed?
     * Everything here no-ops if not.
     */
    private function cellsAvailable(): bool
    {
        if (self::$cellsColumnExists === null) {
            try {
                self::$cellsColumnExists = Schema::hasColumn('rippling_reach', 'max_polygon_cells');
            } catch (\Throwable) {
                self::$cellsColumnExists = false;
            }
        }

        return self::$cellsColumnExists;
    }

    /** Can a max reach be stored/read at all? */
    public function available(): bool
    {
        return $this->cellsAvailable();
    }

    /** Test-only: forget the memoized column check. */
    public static function forgetAvailability(): void
    {
        self::$cellsColumnExists = null;
    }

    /**
     * Is (lat,lng) somewhere this post's reach either covers now or eventually
     * will? False when the post has no reach row (it is not rippling, so there is
     * no "eventually"), and false when max_polygon has not been populated yet -
     * the caller then behaves exactly as it did before this existed.
     *
     * The current polygon is included in the test rather than assumed to be a
     * subset of the max one. It usually is, but a secondary-group rejection clips
     * the current polygon and origin-group union can extend it, so the two are not
     * strictly nested. OR-ing them is both correct and cheaper than a geometry
     * union over polygons that are frequently invalid.
     */
    public function isWithinMaxReach(int $msgid, float $lat, float $lng): bool
    {
        if (!$this->available()) {
            return false;
        }

        try {
            // Both the current and the eventual reach are answered from their
            // stored grids: one keyed read, streaming probes (containsEncoded
            // walks the run stream for one point and allocates nothing - a
            // full decode is 317ms/128MB on a production-sized reach, and this
            // is the reply gate). Undecidable bytes fail closed - the
            // conservative default this gate has always had.
            // Labels-truth first: the maximum reach is the stored label at
            // its own full budget, exactly (and the current reach is a subset
            // of it, so one max-budget verdict answers the whole
            // current-OR-eventual question). Falls through to the cells - and
            // then the conservative default - wherever no label exists or
            // routing is unavailable.
            $verdicts = app(\App\Services\Ripple\ReachService::class)
                ->labelVerdicts($lat, $lng, [$msgid], 'max');
            if (isset($verdicts[$msgid])) {
                return $verdicts[$msgid] === 'in';
            }

            $row = DB::table('rippling_reach')
                ->select('polygon_cells', 'max_polygon_cells')
                ->where('msgid', $msgid)
                ->first();
            if ($row === null) {
                return false;
            }
            if ($row->polygon_cells !== null
                && $this->cellSets->containsEncoded($row->polygon_cells, $lng, $lat) === true) {
                return true;
            }

            return $row->max_polygon_cells !== null
                && $this->cellSets->containsEncoded($row->max_polygon_cells, $lng, $lat) === true;
        } catch (\Throwable $e) {
            // Invalid stored geometry (or a malformed cell set) can throw. A
            // spatial failure must never decide a reply's fate by exception,
            // so fall back to "no".
            Log::warning('firstreply: max reach test failed', ['msgid' => $msgid, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * How many freeglers the post will have reached by the time it stops growing.
     * Null when unknown. Used to tell a poster what is still to come, so a silent
     * first evening reads as "this is still going" rather than "this has failed".
     */
    public function maxCumulativeUsers(int $msgid): ?int
    {
        if (!$this->available()) {
            return null;
        }

        try {
            $val = DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->value('max_cumulative_users');

            return $val === null ? null : (int) $val;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Populate max_polygon for reach rows that lack it.
     *
     * Most rows are free: the final tick's geometry is already inline in the
     * cached schedule, so this is a JSON decode and an UPDATE. The rest need one
     * routing call each, which is why $routingBudget bounds them separately from
     * $limit - a run should never turn into an unbounded fan-out at the routing
     * server just because a batch happened to be full of them.
     *
     * @return array{scanned:int, filled:int, routed:int, skipped:int}
     */
    /**
     * The candidate scan: expanding posts that still lack a max reach, newest
     * first. Public so a test can EXPLAIN the query this actually runs rather
     * than a copy of it - the failure mode being guarded against is a silent
     * change of PLAN, which reading the SQL cannot detect.
     *
     * The planner's unaided choice here is pathological (PR #1404, folded into
     * this branch because the fix it deferred is DDL and this branch is doing
     * DDL). ORDER BY updated_at DESC LIMIT 200 makes it drive off
     * rippling_reach_updated_at and filter row by row - fine while a backlog
     * existed, since the LIMIT filled early. Once every expanding post HAS a
     * max reach the predicate matches NOTHING, and a LIMIT that is never
     * satisfied cannot stop a scan early, so it walks the whole index to prove
     * the empty set: 55,990 row lookups in a ~50GB table, 2m09s on an idle db1,
     * once a minute, on the read node.
     *
     * Two correct forms, and which one is correct is a schema fact rather than
     * a preference. Measured on a 55,015-row clone matching production
     * (8,299 expanding, zero matching rows - the live state):
     *
     *   planner unaided        full updated_at walk
     *   FORCE INDEX (status)   13,696 rows + filesort   <- #1404's fix
     *   composite index             1 row, no filesort  <- once the DDL lands
     *
     * THE TWO DO NOT STACK. Leaving #1404's hint in place once the composite
     * exists pins the planner to the status index, so the composite is never
     * used at all: 15,177 rows and a filesort, worse than no hint. The hint is
     * strictly the pre-DDL fallback, which is why it is one branch or the other
     * and never both.
     */
    public function candidateQuery(int $limit = 200): \Illuminate\Database\Query\Builder
    {
        // Both halves, so the invariant is explicit rather than inferred: the
        // index is built ON has_max_reach, which is generated FROM
        // max_polygon_cells, so it cannot exist without the column. If that
        // ever stops holding, this takes the older, always-valid branch.
        $indexed = MaxReachCandidateIndex::ready() && $this->cellsAvailable();

        return DB::query()
            ->when(
                $indexed,
                fn ($q) => $q->from('rippling_reach'),
                fn ($q) => $q->fromRaw('rippling_reach FORCE INDEX (rippling_reach_status_index)')
            )
            ->select('msgid', 'lat', 'lng', 'schedule')
            // The same condition, written the only way that reaches the index.
            // MySQL does NOT substitute a generated column for its own defining
            // expression: written as `max_polygon_cells IS NULL` the planner
            // ignores the composite entirely and goes back to the updated_at
            // walk. Verified by EXPLAIN, both ways.
            ->when(
                $indexed,
                // has_max_reach is generated from max_polygon_cells alone, so
                // the labelled-row exclusion must be explicit here too: a
                // labelled row whose max grid was DRAINED (ripple:drop-cell-grids)
                // would otherwise be re-selected - and re-filled - every sweep.
                fn ($q) => $q->where('has_max_reach', 0)->whereNull('reach_labels'),
                fn ($q) => $q->when($this->cellsAvailable(), fn ($q2) => $q2->whereNull('max_polygon_cells')->whereNull('reach_labels'))
            )
            ->whereNotNull('schedule')
            // Only posts still expanding: a done post's current reach IS its
            // eventual reach, so no reply to it can be held inside max_polygon
            // and filling one buys nothing. Without this filter the pass,
            // having covered every live post, spent its whole routing budget
            // for days working through completed history (observed 2026-08-08:
            // 320 fills/run against status=done rows after the live backlog
            // emptied).
            ->where('status', 'expanding')
            ->orderByDesc('updated_at')
            ->limit($limit);
    }

    public function populate(int $limit = 200, int $routingBudget = 20): array
    {
        $stats = ['scanned' => 0, 'filled' => 0, 'routed' => 0, 'skipped' => 0];

        if (!$this->available()) {
            return $stats;
        }

        // Labelled rows skip the grid fill (their label answers the gate),
        // but max_cumulative_users - the "will be shown to around N more
        // people" nudge - still needs feeding. It is a plain read of the
        // cached schedule's final tick: no routing call, no grid.
        $stats['labelled_cumulative'] = $this->fillCumulativeForLabelled($limit);

        $rows = $this->candidateQuery($limit)->get()->all();

        foreach ($rows as $row) {
            $stats['scanned']++;

            try {
                $ticks = json_decode((string) $row->schedule, true);
                if (!is_array($ticks) || empty($ticks)) {
                    $stats['skipped']++;
                    continue;
                }

                $final = $this->finalTick($ticks);
                if ($final === null) {
                    $stats['skipped']++;
                    continue;
                }

                $wkt = !empty($final['wkt']) ? (string) $final['wkt'] : null;
                if ($wkt === null) {
                    $driveMin = (float) ($final['drive_min'] ?? 0);
                    if ($driveMin <= 0 || $stats['routed'] >= $routingBudget) {
                        // Either nothing to ask for, or we have spent this run's
                        // routing budget. Leave the row for the next pass.
                        $stats['skipped']++;
                        continue;
                    }
                    $geom = $this->reach->catchmentGeometry((float) $row->lat, (float) $row->lng, $driveMin);
                    $stats['routed']++;
                    if ($geom === null || empty($geom['wkt'])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $wkt = (string) $geom['wkt'];
                }

                $cumulative = isset($final['cumulative_users']) ? (int) $final['cumulative_users'] : null;

                $this->storeMaxPolygon((int) $row->msgid, $wkt, $cumulative);
                $stats['filled']++;
            } catch (\Throwable $e) {
                $stats['skipped']++;
                Log::warning('firstreply: max reach populate failed', [
                    'msgid' => $row->msgid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Fill in one post's max_polygon right now, from its cached schedule only.
     *
     * Exists because scouting fires as soon as a post is seen, and both it and
     * the background populate pass run every minute - so a brand-new post is
     * regularly considered for scouting a beat before its eventual reach is
     * known, and without that nobody is eligible at all. Rather than make the
     * post wait a minute for the next tick of a different cron, the scout path
     * asks for it directly.
     *
     * Never calls the routing server: this is on the path of a job we want to
     * stay fast, and the posts that need a routing call are exactly the ones
     * worth leaving to the background pass. Returns false when it could not fill
     * it, and the caller simply finds nobody eligible this time round.
     */
    public function populateForPost(int $msgid): bool
    {
        if (!$this->available()) {
            return false;
        }

        try {
            $row = DB::table('rippling_reach')
                ->select('schedule')
                ->where('msgid', $msgid)
                // Same "lacks a max reach" rule as populate().
                ->when($this->cellsAvailable(), fn ($q) => $q->whereNull('max_polygon_cells')->whereNull('reach_labels'))
                ->whereNotNull('schedule')
                ->first();

            if ($row === null) {
                // Either no reach row, or it is already populated. Both are
                // "nothing to do here" rather than a failure.
                return false;
            }

            $ticks = json_decode((string) $row->schedule, true);
            if (!is_array($ticks) || empty($ticks)) {
                return false;
            }

            $final = $this->finalTick($ticks);
            if ($final === null || empty($final['wkt'])) {
                return false;
            }

            $this->storeMaxPolygon(
                $msgid,
                (string) $final['wkt'],
                isset($final['cumulative_users']) ? (int) $final['cumulative_users'] : null
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not fill max reach on demand', [
                'msgid' => $msgid, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Work out how long each recorded passthrough would have waited, had it been
     * held.
     *
     * This is the only thing that says whether the passthrough is worth having.
     * The count says the lever fired; this says what firing bought. For each
     * reply, find the first tick whose polygon covers where the replier was, ask
     * the hazard schedule when that tick was due, and measure from when they
     * actually replied.
     *
     * A reply already inside the tick the post had reached scores 0 rather than
     * being discarded - it happens when the reach moved on between the decision
     * and this sweep, and calling that "unknown" would quietly drop the least
     * impressive cases and flatter the average.
     *
     * @return array{scanned:int, computed:int, unknown:int}
     */
    public function computePassthroughSavings(int $limit = 200): array
    {
        $stats = ['scanned' => 0, 'computed' => 0, 'unknown' => 0];

        try {
            $rows = DB::select(
                'SELECT p.id, p.msgid, p.lat, p.lng, p.created_at,
                        rr.schedule, rr.arrival, rr.total_ticks
                 FROM firstreply_passthroughs p
                 JOIN rippling_reach rr ON rr.msgid = p.msgid
                 WHERE p.computed_at IS NULL AND p.lat IS NOT NULL AND p.lng IS NOT NULL
                 ORDER BY p.created_at
                 LIMIT ?',
                [$limit]
            );
        } catch (\Throwable $e) {
            Log::warning('firstreply: passthrough saving sweep failed', ['error' => $e->getMessage()]);

            return $stats;
        }

        $hazard = $this->reach->hazardHours();

        foreach ($rows as $row) {
            $stats['scanned']++;

            $waited = null;

            try {
                $ticks = json_decode((string) $row->schedule, true);
                if (is_array($ticks) && !empty($ticks) && $row->arrival !== null) {
                    $tick = $this->firstTickCovering($ticks, (float) $row->lat, (float) $row->lng);
                    if ($tick !== null) {
                        // Tick 1 is live from arrival (it is the clamped initial value, not a
                        // threshold); tick k (k>=2) starts at hazardHours[k-1], the threshold
                        // that promotes the post INTO that tick.
                        //
                        // hazardHours is 0-indexed and $tick is 1-based, so this is [$tick - 1],
                        // matching ReachService::tickForElapsedHours (which sets tick = $i + 1
                        // once elapsed >= hazardHours[$i]) and nextExpansionAfter. Live rows
                        // agree: reaches that finish at tick k do so exactly hazardHours[k]
                        // hours after arrival - tick 1 at 3.0h, tick 4 at 24.0h, tick 8 at
                        // 168.0h - which is the moment they would have advanced again.
                        $dueHours = $tick >= 2 ? ($hazard[$tick - 1] ?? null) : 0;
                        if ($dueHours !== null) {
                            $due = Carbon::parse($row->arrival)->addHours((float) $dueHours);
                            $repliedAt = Carbon::parse($row->created_at);
                            $waited = max(0.0, $repliedAt->diffInSeconds($due, false) / 3600.0);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('firstreply: could not size a passthrough', [
                    'id' => $row->id, 'error' => $e->getMessage(),
                ]);
            }

            // computed_at is stamped either way so an unanswerable row is not
            // rescanned forever; waited_hours stays NULL and the dashboard
            // averages only the rows it could actually answer.
            DB::table('firstreply_passthroughs')->where('id', $row->id)->update([
                'waited_hours' => $waited,
                'computed_at' => now(),
            ]);

            if ($waited === null) {
                $stats['unknown']++;
            } else {
                $stats['computed']++;
            }
        }

        return $stats;
    }

    /**
     * The one write of the max reach, shared by both populate paths. The grid
     * IS the stored form, so a failed rasterise must not write anything - the
     * row stays unfilled for the next pass, exactly like a failed routing
     * call.
     */
    /**
     * max_cumulative_users for rows the label answers: the schedule's final
     * tick already carries the audience count, so no routing call and no
     * grid materialisation - just the one column the engagement nudge reads.
     */
    private function fillCumulativeForLabelled(int $limit): int
    {
        $filled = 0;
        try {
            $rows = DB::table('rippling_reach')
                ->select('msgid', 'schedule')
                ->whereNotNull('reach_labels')
                ->whereNull('max_cumulative_users')
                ->whereNotNull('schedule')
                ->where('status', 'expanding')
                ->limit($limit)
                ->get();
            foreach ($rows as $row) {
                $ticks = json_decode((string) $row->schedule, true);
                $final = is_array($ticks) && !empty($ticks) ? $this->finalTick($ticks) : null;
                if ($final === null || !isset($final['cumulative_users'])) {
                    continue;
                }
                DB::table('rippling_reach')->where('msgid', $row->msgid)->update([
                    'max_cumulative_users' => (int) $final['cumulative_users'],
                ]);
                $filled++;
            }
        } catch (\Throwable $e) {
            Log::warning('firstreply: labelled cumulative fill failed', ['error' => $e->getMessage()]);
        }

        return $filled;
    }

    private function storeMaxPolygon(int $msgid, string $wkt, ?int $cumulative): void
    {
        $cells = $this->cellSets->rasterize($wkt);
        if ($cells === null) {
            throw new \RuntimeException('max reach rasterise failed; row left for the next pass');
        }
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'max_polygon_cells' => $cells,
            'max_cumulative_users' => $cumulative,
        ]);
    }

    /**
     * The lowest tick of THIS post's schedule whose polygon covers (lat,lng),
     * or null when the schedule cannot say. Wraps firstTickCovering so callers
     * need not know how the schedule is stored or parsed.
     */
    public function tickCovering(int $msgid, float $lat, float $lng): ?int
    {
        $schedule = DB::table('rippling_reach')->where('msgid', $msgid)->value('schedule');
        if (!$schedule) {
            return null;
        }

        $ticks = json_decode((string) $schedule, true);
        if (!is_array($ticks) || empty($ticks)) {
            return null;
        }

        return $this->firstTickCovering($ticks, $lat, $lng);
    }

    /**
     * The lowest tick number whose polygon contains (lat,lng), or null when none
     * of them do (or none carries geometry to test).
     *
     * Ticks are sorted rather than assumed ordered, for the same reason finalTick
     * takes the highest tick number rather than the last element.
     *
     * @param array<int,mixed> $ticks
     */
    public function firstTickCovering(array $ticks, float $lat, float $lng): ?int
    {
        $withGeometry = [];
        foreach ($ticks as $entry) {
            if (is_array($entry) && !empty($entry['wkt'])) {
                $withGeometry[] = $entry;
            }
        }

        usort($withGeometry, static fn ($a, $b) => (int) ($a['tick'] ?? 0) <=> (int) ($b['tick'] ?? 0));

        foreach ($withGeometry as $entry) {
            try {
                $row = DB::selectOne(
                    'SELECT ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), '
                    . 'ST_SRID(POINT(?, ?), ' . self::SRID . ')) AS inside',
                    [(string) $entry['wkt'], $lng, $lat]
                );
                if ((int) ($row->inside ?? 0) === 1) {
                    return (int) ($entry['tick'] ?? 0);
                }
            } catch (\Throwable) {
                // Invalid stored geometry: skip this tick rather than abandoning
                // the row, since a later tick may still answer.
                continue;
            }
        }

        return null;
    }

    /**
     * The widest tick in a schedule. Highest tick number rather than last array
     * element: the schedule is built in order today, but relying on that would
     * make a reordered payload silently narrow every post's reach.
     *
     * @param array<int,mixed> $ticks
     * @return array<string,mixed>|null
     */
    private function finalTick(array $ticks): ?array
    {
        $best = null;
        $bestTick = -1;

        foreach ($ticks as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tick = (int) ($entry['tick'] ?? 0);
            if ($tick > $bestTick) {
                $bestTick = $tick;
                $best = $entry;
            }
        }

        return $best;
    }
}
