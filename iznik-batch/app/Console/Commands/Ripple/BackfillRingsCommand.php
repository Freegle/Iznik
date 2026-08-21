<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ReachService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:backfill-rings — give posts that were rippled BEFORE the overflow lanes
 * existed the rings they would have been given at initialisation.
 *
 * Rings are written when a schedule is derived, which happens at initialisation
 * (and on recompute). Advancing a tick does not derive one, so every post that
 * was already live when a lane was switched on stays ringless for the rest of
 * its life: at enablement 28,504 still-open posts had no rings, and the lane
 * would only ever have applied to newly-posted ones.
 *
 * Deliberately NOT ripple:recompute-reach, which is the other thing that writes
 * rings: that command re-derives the schedule and shrinks the post's audience to
 * the current cap, so using it here would change the reach of tens of thousands
 * of live posts as a side effect of adding a fallback ring. It also skips any
 * post the cap does not bind on, which is most of them now, so it would have
 * ringed only a legacy subset.
 *
 * This writes overflow_bounds and NOTHING else:
 *  - the committed reach (polygon, schedule, audience, groups) is untouched, so
 *    no member loses or gains a post they can already see;
 *  - updated_at is preserved, so the reach mailer does not reconsider the row
 *    (a bulk reach backfill previously generated 38k+ notification emails in a
 *    morning) and the spatial delta poll has nothing to resync;
 *  - a ring that turns out to sit inside the committed reach is harmless: the
 *    ring is only ever consulted once the reach itself has said no.
 *
 * Cost is one routing /v1/ripple-schedule call per DISTINCT origin (a Dijkstra
 * plus ring rasterisation). Origins are deduplicated first because the schedule
 * is a deterministic function of the blurred origin and the config, which is the
 * same property ExpandService's reuse relies on. The routing server runs on this
 * host, so the run is chunked, load-guarded and resumable rather than fanned out
 * (2026-07-01: a 3-shard recompute drain took this box to load 70+ and starved
 * the embedder).
 */
class BackfillRingsCommand extends Command
{
    protected $signature = 'ripple:backfill-rings
                            {--limit=200 : Max DISTINCT origins to process this run}
                            {--concurrency=2 : Origins computed at once (routing runs on this host)}
                            {--max-load=12 : Pause while the host 1-minute load is above this}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
                            {--dry-run : Report what would be written without writing}';

    protected $description = 'Backfill overflow rings onto open posts rippled before the lanes existed';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_backfill_rings_last_msgid';

    public function handle(ReachService $reach): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $concurrency = max(1, (int) $this->option('concurrency'));
        $maxLoad = (float) $this->option('max-load');

        if (!$this->columnReady()) {
            $this->error('rippling_reach.overflow_bounds does not exist yet — apply the schema first.');

            return Command::FAILURE;
        }

        $after = $this->option('reset-mark') ? 0 : (
            $this->option('after') !== null ? (int) $this->option('after') : $this->mark()
        );

        // Still-open posts only: a deleted or completed post will never be
        // replied to, so a ring for it would be spend with no possible benefit.
        $q = DB::table('rippling_reach as rr')
            ->join('messages as m', 'm.id', '=', 'rr.msgid')
            ->leftJoin('messages_outcomes as mo', 'mo.msgid', '=', 'rr.msgid')
            ->select(['rr.msgid', 'rr.lat', 'rr.lng', 'rr.max_minutes_cap'])
            ->whereIn('rr.status', ['expanding', 'done'])
            ->whereNull('rr.overflow_bounds')
            ->whereNull('m.deleted')
            ->whereNull('mo.id')
            ->where('rr.msgid', '>', $after);

        // The rural lane only rings posts the audience cap actually bound, so a
        // post whose whole reachable pool is under the cap can never earn one -
        // asking routing about it costs a Dijkstra to be told nothing applies
        // (measured: 16,667 of 28,492 candidates at backfill time). Skip those
        // ONLY while rural is the sole lane running.
        //
        // Fairness and cluster are both the opposite case: they ring posts the
        // cap did NOT bind. Cluster's floor now defaults to the cap itself, so
        // with it on, every sub-cap post is a candidate again - and those are
        // precisely the posts this backfill exists to reach. Leaving the filter
        // in would have let a full drain report "nothing left to backfill" while
        // never once asking about the semi-rural posts that carry no ring.
        $target = (int) config('freegle.ripple.extent.target_users', 0);
        $fairnessOn = (bool) config('freegle.ripple.fairness.enabled', false);
        $clusterOn = (bool) config('freegle.ripple.cluster.enabled', false);
        if (!$fairnessOn && !$clusterOn && $target > 0) {
            $q->where('rr.total_freeglers', '>', $target);
        }

        // Origins repeat, so read more rows than the origin budget to fill it.
        $rows = $q->orderBy('rr.msgid')->limit($limit * 20)->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing left to backfill' . ($after > 0 ? " after msgid {$after}." : '.'));

            return Command::SUCCESS;
        }

        // Group by origin: same blurred point and same travel budget means the
        // same schedule, so one routing call serves every post that shares them.
        $byOrigin = [];
        foreach ($rows as $row) {
            $key = sprintf('%.4f|%.4f|%s', (float) $row->lat, (float) $row->lng, $row->max_minutes_cap ?? '');
            if (!isset($byOrigin[$key]) && count($byOrigin) >= $limit) {
                continue;
            }
            $byOrigin[$key][] = $row;
        }

        $stats = ['origins' => 0, 'posts' => 0, 'ringed' => 0, 'no_rings' => 0, 'failed' => 0, 'paused' => 0];
        $lastMsgid = $after;

        foreach (array_chunk($byOrigin, $concurrency, true) as $chunk) {
            $stats['paused'] += $this->waitForLoad($maxLoad);

            $origins = [];
            foreach ($chunk as $key => $group) {
                $first = $group[0];
                $origins[$key] = [
                    'lat' => (float) $first->lat,
                    'lng' => (float) $first->lng,
                    'max_minutes' => $first->max_minutes_cap !== null ? (float) $first->max_minutes_cap : null,
                ];
            }

            $schedules = $reach->computeSchedulesBatch(array_values($origins));
            $keys = array_keys($origins);

            foreach ($keys as $i => $key) {
                $schedule = $schedules[$i] ?? null;
                $group = $chunk[$key];
                $stats['origins']++;
                $stats['posts'] += count($group);

                $bounds = is_array($schedule) ? ($schedule['overflow_bounds'] ?? null) : null;
                if (!is_array($bounds) || empty($bounds)) {
                    // Either routing could not answer, or no lane applies to this
                    // origin — a post the cap never bound on has nothing to
                    // overflow into. Both leave the row NULL, which is correct.
                    $stats[is_array($schedule) ? 'no_rings' : 'failed']++;
                    foreach ($group as $row) {
                        $lastMsgid = max($lastMsgid, (int) $row->msgid);
                    }
                    continue;
                }

                $json = json_encode($bounds);
                foreach ($group as $row) {
                    if (!$dryRun) {
                        // One row per statement (msgid is the primary key). The
                        // self-assignment holds updated_at still so the reach
                        // mailer and the spatial delta poll both ignore the
                        // write; the builder has no way to say "leave this
                        // column alone" against MySQL's ON UPDATE auto-bump.
                        // keep-raw: updated_at = updated_at suppresses the ON UPDATE CURRENT_TIMESTAMP bump
                        DB::update(
                            'UPDATE rippling_reach SET overflow_bounds = ?, updated_at = updated_at
                              WHERE msgid = ? AND overflow_bounds IS NULL',
                            [$json, $row->msgid]
                        );

                    }
                    $stats['ringed']++;
                    $lastMsgid = max($lastMsgid, (int) $row->msgid);
                }
            }
        }

        if (!$dryRun && $lastMsgid > $after) {
            $this->saveMark($lastMsgid);
        }

        $this->info(sprintf(
            '%s %d post(s) across %d origin(s): %d ringed, %d origin(s) with no lane applicable, %d origin(s) routing could not answer%s. Mark now %d.',
            $dryRun ? 'Would backfill' : 'Backfilled',
            $stats['posts'],
            $stats['origins'],
            $stats['ringed'],
            $stats['no_rings'],
            $stats['failed'],
            $stats['paused'] > 0 ? sprintf(' (paused %ds for load)', $stats['paused']) : '',
            $lastMsgid,
        ));

        return Command::SUCCESS;
    }

    /**
     * Hold off while the host is busy. /proc/loadavg is not namespaced, so this
     * is the real host figure the routing server is competing for. Gives up
     * waiting after two minutes and lets the chunk run: the run is bounded
     * anyway, and a permanently busy host would otherwise stall the sweep
     * silently.
     */
    private function waitForLoad(float $maxLoad): int
    {
        $waited = 0;
        while ($waited < 120) {
            $load = sys_getloadavg()[0] ?? 0.0;
            if ($load <= $maxLoad) {
                return $waited;
            }
            sleep(10);
            $waited += 10;
        }

        return $waited;
    }

    private function columnReady(): bool
    {
        try {
            return DB::table('information_schema.columns')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', 'rippling_reach')
                ->where('column_name', 'overflow_bounds')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function mark(): int
    {
        $row = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->first();

        return $row && $row->value !== null && $row->value !== '' ? (int) $row->value : 0;
    }

    private function saveMark(int $msgid): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => self::CONFIG_KEY_MARK],
            ['value' => (string) $msgid]
        );
    }
}
