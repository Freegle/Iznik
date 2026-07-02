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
 * Idempotent (skips rows that already have a note), bounded per run (--limit), and disabled by a
 * dedicated flag (freegle.ripple.proximity_notes) so it can be turned off without touching the
 * master RIPPLE_ENABLED switch.
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

        // Rippled-in copies still inside the ripple window that have no note yet, with the origin
        // (degrees) from the reach row. leftJoin+whereNull keeps it idempotent; the group's own
        // posts (rippled_in=0) are excluded.
        $rows = DB::table('messages_groups as mg')
            ->join('rippling_reach as rr', 'rr.msgid', '=', 'mg.msgid')
            ->leftJoin('rippling_proximity as rp', function ($j) {
                $j->on('rp.msgid', '=', 'mg.msgid')->on('rp.groupid', '=', 'mg.groupid');
            })
            ->where('mg.rippled_in', 1)
            ->whereNull('rp.msgid')
            ->where('mg.arrival', '>=', now()->subDays(8))
            ->orderByDesc('mg.arrival')
            ->limit((int) $this->option('limit'))
            ->get(['mg.msgid', 'mg.groupid', 'rr.lat', 'rr.lng']);

        $written = 0;
        foreach ($rows as $r) {
            try {
                $prox = $reach->groupProximity((float) $r->lat, (float) $r->lng, (int) $r->groupid);
                if ($prox === null || !($prox['quicker'] ?? false)) {
                    continue; // not quicker, or routing unreachable — no note (re-tried next run)
                }
                $p = Location::describeNearest((float) $prox['closest']['lat'], (float) $prox['closest']['lng']);
                $q = Location::describeNearest((float) $prox['furthest']['lat'], (float) $prox['furthest']['lng']);
                if ($p === null || $q === null) {
                    continue;
                }
                DB::table('rippling_proximity')->insertOrIgnore([
                    'msgid' => (int) $r->msgid,
                    'groupid' => (int) $r->groupid,
                    'p' => $p,
                    'q' => $q,
                ]);
                $written++;
            } catch (\Throwable $e) {
                Log::warning("ripple: proximity note failed for msg {$r->msgid} group {$r->groupid}: {$e->getMessage()}");
            }
        }

        $this->info("ripple:proximity-notes wrote {$written} of {$rows->count()} candidate notes");

        return Command::SUCCESS;
    }
}
