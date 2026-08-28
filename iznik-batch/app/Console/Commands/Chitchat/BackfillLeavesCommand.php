<?php

namespace App\Console\Commands\Chitchat;

use App\Console\Concerns\PreventsOverlapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * One-shot backfill of newsfeed.leaf - the road-network region tag the
 * road-aware ChitChat feed narrowing reads - for threads that predate
 * tagging. Safe to re-run (tagged rows are skipped) and safe against a
 * routing server without the reach engine (failures leave rows NULL; run
 * again once REACH_DIR is deployed). Only top-level threads within the
 * feed's own age window matter: replies inherit their thread's visibility
 * and older threads are never served.
 */
class BackfillLeavesCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'chitchat:backfill-leaves
                            {--dry-run : Report how many rows would be tagged without changing anything}
                            {--limit=0 : Max rows to tag (0 = no limit)}
                            {--sleep-ms=20 : Pause between rows}';

    protected $description = 'Tag existing chitchat threads with their road-network region';

    public function handle(): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        $routing = rtrim((string) config('freegle.routing_server_url'), '/');
        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;

        $query = DB::table('newsfeed')
            ->select([DB::raw('id'), DB::raw('ST_Y(position) AS lat'), DB::raw('ST_X(position) AS lng')])
            ->whereNull('leaf')
            ->whereNull('replyto')
            ->whereNull('deleted')
            ->whereNotNull('position')
            ->where('timestamp', '>=', now()->subDays(31));

        $this->info((clone $query)->count() . ' rows need tagging.');
        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $done = $failed = 0;
        foreach ($query->cursor() as $row) {
            $leaf = null;
            try {
                $r = Http::timeout(3)->get("{$routing}/v1/leaf", [
                    'lat' => (float) $row->lat,
                    'lng' => (float) $row->lng,
                ]);
                if ($r->successful()) {
                    $leaves = $r->json('leaves');
                    if (is_array($leaves) && $leaves !== []) {
                        $leaf = (int) $leaves[0];
                    }
                }
            } catch (\Throwable) {
                // Fail soft; retried on the next run.
            }
            if ($leaf !== null) {
                DB::table('newsfeed')->where('id', $row->id)->update(['leaf' => $leaf]);
                $done++;
            } else {
                $failed++;
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->info("Tagged {$done} rows; {$failed} could not be tagged (retry after the reach engine is deployed).");

        return Command::SUCCESS;
    }
}
