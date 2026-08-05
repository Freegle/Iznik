<?php

namespace App\Services\FirstReply;

use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Will this post ever reach here?", as opposed to "does it reach here yet?".
 *
 * Rippling grows a post's reach over days. rippling_reach.polygon is where it has
 * got to; the tick schedule cached alongside it already describes where it is
 * GOING, because the routing server computes the whole schedule up front. Nothing
 * used that, so every gate could only ask about the present.
 *
 * The difference matters in one specific place: a reply from someone the post has
 * not reached yet, but will. Holding that reply back does not protect local-first
 * ordering in any meaningful way, because the person is going to be allowed to
 * reply anyway - it just makes their reply late. On a post that already has
 * replies that trade is fine. On a post that has NONE it is actively harmful,
 * because a delayed first reply and no reply at all feel identical to the poster.
 *
 * The final-tick geometry is copied into rippling_reach.max_polygon by a
 * background pass rather than derived on demand, because some schedule entries
 * carry no inline WKT and have to be re-fetched from the routing server. A
 * point-in-polygon test on a reply is not somewhere to discover that. Rows the
 * pass has not reached yet keep max_polygon NULL, and every reader treats NULL as
 * "no wider reach known", which degrades exactly to today's behaviour.
 */
class MaxReachService
{
    private const SRID = 3857;

    /** Memoized column-existence check so a pre-migration deploy is a no-op. */
    private static ?bool $columnExists = null;

    public function __construct(private ReachService $reach)
    {
    }

    /** Has the max_polygon migration run? Everything here no-ops if not. */
    public function available(): bool
    {
        if (self::$columnExists === null) {
            try {
                self::$columnExists = Schema::hasColumn('rippling_reach', 'max_polygon');
            } catch (\Throwable) {
                self::$columnExists = false;
            }
        }

        return self::$columnExists;
    }

    /** Test-only: forget the memoized column check. */
    public static function forgetAvailability(): void
    {
        self::$columnExists = null;
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
            $point = 'ST_SRID(POINT(?, ?), ' . self::SRID . ')';
            $row = DB::selectOne(
                "SELECT EXISTS(
                    SELECT 1 FROM rippling_reach
                    WHERE msgid = ?
                      AND (ST_Contains(polygon, $point) = 1
                           OR (max_polygon IS NOT NULL AND ST_Contains(max_polygon, $point) = 1))
                 ) AS within",
                [$msgid, $lng, $lat, $lng, $lat]
            );

            return (bool) ($row->within ?? 0);
        } catch (\Throwable $e) {
            // Invalid stored geometry can make ST_Contains throw. A spatial failure
            // must never decide a reply's fate by exception, so fall back to "no".
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
            $row = DB::selectOne('SELECT max_cumulative_users FROM rippling_reach WHERE msgid = ?', [$msgid]);
            $val = $row->max_cumulative_users ?? null;

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
    public function populate(int $limit = 200, int $routingBudget = 20): array
    {
        $stats = ['scanned' => 0, 'filled' => 0, 'routed' => 0, 'skipped' => 0];

        if (!$this->available()) {
            return $stats;
        }

        $rows = DB::select(
            'SELECT msgid, lat, lng, schedule
             FROM rippling_reach
             WHERE max_polygon IS NULL AND schedule IS NOT NULL
             ORDER BY updated_at DESC
             LIMIT ?',
            [$limit]
        );

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

                DB::statement(
                    'UPDATE rippling_reach
                     SET max_polygon = ST_GeomFromText(?, ' . self::SRID . '),
                         max_cumulative_users = ?
                     WHERE msgid = ?',
                    [$wkt, $cumulative, $row->msgid]
                );
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
