<?php

namespace App\Services\FirstReply;

use App\Services\Ripple\ReachService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Will this post ever reach here?", as opposed to "does it reach here yet?".
 *
 * Rippling grows a post's reach over days. The stored road-network label
 * already describes where it is GOING - the label is computed once at the
 * post's full budget, so evaluating it at that budget answers the eventual
 * reach exactly.
 *
 * The difference matters in one specific place: a reply from someone the post has
 * not reached yet, but will. Holding that reply back does not protect local-first
 * ordering in any meaningful way, because the person is going to be allowed to
 * reply anyway - it just makes their reply late. On a post that already has
 * replies that trade is fine. On a post that has NONE it is actively harmful,
 * because a delayed first reply and no reply at all feel identical to the poster.
 *
 * The answer comes from the routing server per question. No verdict - the
 * label not stored yet, or the server unreachable - holds the reply, the
 * conservative default this gate has always had.
 */
class MaxReachService
{
    private const SRID = 3857;

    public function __construct(private ReachService $reach)
    {
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
        try {
            // The stored label at its own full budget IS the eventual reach
            // (and the current reach is inside it, so one verdict answers the
            // whole current-or-eventual question). No verdict - the label not
            // stored yet, or the routing server unreachable - holds the
            // reply: the conservative default this gate has always had.
            // There is no grid fallback; routing is a dependency, by design.
            $verdicts = app(\App\Services\Ripple\ReachService::class)
                ->labelVerdicts($lat, $lng, [$msgid], 'max');

            return ($verdicts[$msgid] ?? '') === 'in';
        } catch (\Throwable $e) {
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
        try {
            $val = DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->value('max_cumulative_users');

            return $val === null ? null : (int) $val;
        } catch (\Throwable) {
            return null;
        }
    }

    public function populate(int $limit = 200, int $routingBudget = 20): array
    {
        $stats = ['scanned' => 0, 'filled' => 0, 'routed' => 0, 'skipped' => 0];

        // The one remaining fill: max_cumulative_users - the "will be shown
        // to around N more people" nudge - read from the cached schedule's
        // final tick. The grid sweep is gone: the stored label answers the
        // gate, and there is nothing else the grid told anyone.
        $stats['labelled_cumulative'] = $this->fillCumulativeForLabelled($limit);

        return $stats;
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
