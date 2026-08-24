<?php

namespace App\Console\Commands\Ripple;

use App\Models\Location;
use App\Services\Ripple\ReachService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:proximity-notes — computes the moderator "quicker to get to" P/Q note for posts that
 * have rippled into a group, OUT of the hot ripple:expand cron so its (slacker) routing/KNN calls
 * can never slow rippling. Best-effort: finds recently rippled-in (msgid,groupid) rows that don't
 * yet have a rippling_proximity row, resolves P (nearest in-group point to the offer) and Q
 * (furthest in-group point from P) to place names, and stores them only when quicker=true.
 *
 * Negative memoization (Phase 0, plans/routing-performance-step-change.md): every DEFINITIVE
 * routing answer — note written, not quicker, or unreachable within budget — also writes a
 * checked-once-forever marker to rippling_proximity_checked, and marked rows are excluded from
 * future runs. Without this, "no note needed" rows (the majority) were recomputed every 5-minute
 * run for the whole 8-day window: up to ~12 CPU-hours/day of wasted routing work (the 2026-07-06
 * group-21521 Sentry storm's standing tax). Failed calls (PROX_ERROR: timeout/non-2xx/mid-restart)
 * are NOT marked, so those rows retry next run.
 *
 * Idempotent (skips rows that already have a note or a marker), bounded per run (--limit), and
 * disabled by a dedicated flag (freegle.ripple.proximity_notes) so it can be turned off without
 * touching the master RIPPLE_ENABLED switch.
 */
class ProximityNotesCommand extends Command
{
    protected $signature = 'ripple:proximity-notes
                            {--limit=200 : Max un-noted rippled-in rows to process this run}';

    protected $description = 'Best-effort: compute the rippled-in "quicker to get to" moderator note';

    public function handle(ReachService $reach): int
    {
        if (!config('freegle.ripple.proximity_notes', true)) {
            return Command::SUCCESS;
        }

        // Purge markers past any possible candidacy: candidates require mg.arrival within 8
        // days, so a marker older than that can never match again. 14 days keeps a margin;
        // cheap (indexed on checked_at). Checked-once-forever is preserved by the arrival
        // window, not by the marker's lifetime.
        DB::table('rippling_proximity_checked')->where('checked_at', '<', now()->subDays(14))->delete();

        // Rippled-in copies still inside the ripple window that have no note yet AND no
        // checked-once-forever marker, with the origin (degrees) from the reach row.
        // leftJoin+whereNull keeps it idempotent; the group's own posts (rippled_in=0) are
        // excluded. The rp join stays alongside the marker join so pre-marker note rows
        // (written before rippling_proximity_checked existed) are still excluded.
        $rows = DB::table('messages_groups as mg')
            ->join('rippling_reach as rr', 'rr.msgid', '=', 'mg.msgid')
            ->leftJoin('rippling_proximity as rp', function ($j) {
                $j->on('rp.msgid', '=', 'mg.msgid')->on('rp.groupid', '=', 'mg.groupid');
            })
            ->leftJoin('rippling_proximity_checked as rpc', function ($j) {
                $j->on('rpc.msgid', '=', 'mg.msgid')->on('rpc.groupid', '=', 'mg.groupid');
            })
            ->where('mg.rippled_in', 1)
            ->whereNull('rp.msgid')
            ->whereNull('rpc.msgid')
            ->where('mg.arrival', '>=', now()->subDays(8))
            ->orderByDesc('mg.arrival')
            ->limit((int) $this->option('limit'))
            ->get(['mg.msgid', 'mg.groupid', 'rr.lat', 'rr.lng', 'rr.max_drive_min']);

        $written = 0;
        foreach ($rows as $r) {
            try {
                // Bound the proximity exploration to the post's reach budget (not the routing
                // server default of 120 min) — the post only rippled within its reach, so
                // over-exploring is pure waste and trips the slow-call Sentry warning.
                $budget = isset($r->max_drive_min) ? (float) $r->max_drive_min : null;
                $prox = $reach->groupProximity((float) $r->lat, (float) $r->lng, (int) $r->groupid, $budget);
                if ($prox['status'] === ReachService::PROX_ERROR) {
                    continue; // no usable answer (routing down/mid-restart) — retry next run, never memoized
                }

                if ($prox['status'] === ReachService::PROX_OK && ($prox['body']['quicker'] ?? false)) {
                    // Name the P/Q points with postcodes INSIDE this group - a point
                    // near the group edge would otherwise be named with a neighbouring
                    // group's postcode, making the note read as nonsense (Discourse 9808/583).
                    $p = Location::describeNearestInGroup((float) $prox['body']['closest']['lat'], (float) $prox['body']['closest']['lng'], (int) $r->groupid);
                    $q = Location::describeNearestInGroup((float) $prox['body']['furthest']['lat'], (float) $prox['body']['furthest']['lng'], (int) $r->groupid);
                    if ($p === null || $q === null) {
                        continue; // place names unavailable (KNN gap) — retry next run rather than mark half-done
                    }
                    DB::table('rippling_proximity')->insertOrIgnore([
                        'msgid' => (int) $r->msgid,
                        'groupid' => (int) $r->groupid,
                        'p' => $p,
                        'q' => $q,
                    ]);
                    $written++;
                }

                // Definitive outcome (note written, not quicker, or unreachable): write the
                // checked-once-forever marker so this row is never re-queried.
                DB::table('rippling_proximity_checked')->insertOrIgnore([
                    'msgid' => (int) $r->msgid,
                    'groupid' => (int) $r->groupid,
                ]);
            } catch (\Throwable $e) {
                Log::warning("ripple: proximity note failed for msg {$r->msgid} group {$r->groupid}: {$e->getMessage()}");
            }
        }

        $this->info("ripple:proximity-notes wrote {$written} of {$rows->count()} candidate notes");

        return Command::SUCCESS;
    }
}
