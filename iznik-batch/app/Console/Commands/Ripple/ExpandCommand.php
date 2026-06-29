<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:expand — maintains the rippling-out reach (rippling_reach) for every
 * active post. Runs every minute in the batch container (see routes/console.php),
 * computing reach via the routing server. Dark in PR A: nothing reads the reach
 * yet, so this only populates rippling_reach.
 */
class ExpandCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'ripple:expand
                            {--dry-run : Compute and report without writing reach}
                            {--limit=50 : Max posts to initialise/advance this run. initialiseNew computes ALL schedules (Phase 1) before writing any (Phase 2), so on a memory-pressured routing host a large batch stalls the whole tick before any reach row lands; 50 keeps each tick small and completing, and the per-minute cron still drains the backlog quickly}
                            {--msgid= : Restrict the whole run to a single message ID (controlled testing)}
                            {--within-poly= : Restrict the run to posts whose origin lies within this WKT polygon (area testing)}
                            {--within-group= : Restrict the run to posts within these group IDs\' area - comma-separated, resolved to the union of their polygons (group experiment / area testing)}';

    protected $description = 'Maintain rippling-out reach (rippling_reach) for active posts';

    public function handle(ExpandService $service): int
    {
        $this->registerShutdownHandlers();

        // Hard single-instance guard. The scheduler's withoutOverlapping() is unreliable for
        // runInBackground() jobs: the overlap mutex is released as soon as the foreground tick
        // forks the background process, so a fresh run launches every minute even while the
        // previous one is still going. On 2026-06-26 that let ~90 ripple:expand runs pile up,
        // each holding messages_groups/logs row locks across slow spatial INSERT...SELECTs and
        // starving the serial background-tasks worker (1205 lock-wait storm + backlog). This
        // DB-backed lock (CACHE_STORE=database -> cache_locks table) is owned by this process
        // and auto-expires, so at most one bulk run executes at a time and a crashed run can't
        // wedge the lock forever. Controlled one-offs (--msgid / --dry-run) are exempt: they do
        // no bulk expansion and an operator must be able to run them alongside the cron.
        $exemptFromLock = $this->option('msgid') !== null || (bool) $this->option('dry-run');
        $lock = $exemptFromLock ? null : Cache::lock('ripple:expand:run', 1800);
        if ($lock !== null && !$lock->get()) {
            Log::info('ripple:expand skipped: another run already holds the lock');
            $this->info('Another ripple:expand run is in progress; exiting.');

            return Command::SUCCESS;
        }

        try {
            return $this->runExpansion($service);
        } finally {
            $lock?->release();
        }
    }

    /**
     * The actual expansion run, guarded by the single-instance lock in handle().
     */
    private function runExpansion(ExpandService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Mirror the current trial group set (RIPPLE_WITHIN_GROUPS) into the shared
        // `config` table (key 'ripple.within_groups') so the Go API - which runs on a
        // different server and can't read this batch env var - can scope the rippling
        // dashboard to just the trial groups. Cheap upsert; skipped on --dry-run.
        if (!$dryRun) {
            $this->publishTrialGroups();
        }

        $limit = max(1, (int) $this->option('limit'));
        $onlyMsgid = $this->option('msgid') !== null ? (int) $this->option('msgid') : null;

        // Resolve the area scope (one post wins over an area if both somehow given). --within-group
        // is a convenience that resolves to the group's stored polygon (groups.polyindex).
        $withinPolyWkt = $this->resolveWithinPoly();
        if ($withinPolyWkt === false) {
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN — no reach will be written.');
        }
        if ($onlyMsgid !== null) {
            $this->info("Restricting run to message ID: {$onlyMsgid}");
        } elseif ($withinPolyWkt !== null) {
            $this->info('Restricting run to posts within the given polygon.');
        }

        Log::info('ripple:expand starting', [
            'dry_run' => $dryRun, 'limit' => $limit, 'msgid' => $onlyMsgid,
            'within_poly' => $withinPolyWkt !== null,
        ]);

        $stats = $service->process($dryRun, $limit, $onlyMsgid, $withinPolyWkt);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info(sprintf(
            '%sInitialised: %d, Expanded: %d (completed: %d), Removed: %d, Skipped: %d, Errors: %d',
            $prefix,
            $stats['initialized'],
            $stats['expanded'],
            $stats['completed'],
            $stats['removed'],
            $stats['skipped'],
            $stats['errors']
        ));

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('ripple:expand complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Publish the current rippling trial group set into the shared `config` table.
     *
     * The trial groups live in RIPPLE_WITHIN_GROUPS (config freegle.ripple.within_groups),
     * which only the batch container sees. The Go API runs on a separate server, so it reads
     * the value from the `config` table (key 'ripple.within_groups') to scope the rippling
     * dashboard to the trial groups. We upsert a comma-separated id list on each run.
     */
    protected function publishTrialGroups(): void
    {
        $withinGroups = array_map('strval', (array) config('freegle.ripple.within_groups', []));

        DB::table('config')->upsert(
            [['key' => 'ripple.within_groups', 'value' => implode(',', $withinGroups)]],
            ['key'],
            ['value'],
        );
    }

    /**
     * Resolve the optional area scope into a WKT polygon string for the service.
     *
     * --within-poly takes a raw WKT polygon. --within-group is a convenience that loads the
     * group's stored area polygon (groups.polyindex). Returns null when neither is given,
     * the WKT string when an area is requested, or false on a usage/lookup error (so the
     * caller aborts rather than silently rippling the whole eligible set).
     *
     * @return string|null|false
     */
    private function resolveWithinPoly()
    {
        $poly = $this->option('within-poly');
        $groupId = $this->option('within-group');

        if ($poly !== null && $groupId !== null) {
            $this->error('Use only one of --within-poly or --within-group, not both.');

            return false;
        }

        if ($poly !== null) {
            $poly = trim($poly);
            if ($poly === '') {
                $this->error('--within-poly was empty.');

                return false;
            }

            return $poly;
        }

        if ($groupId !== null) {
            // A comma-separated list of group ids (the experiment scope). Resolve to the UNION of
            // their stored area polygons (the same polyindex the cross-post step tests) so one run
            // ripples every experiment group's posts. The scheduled cron passes the env-backed list
            // (freegle.ripple.within_groups); it can also be given by hand for area testing.
            $groupIds = array_values(array_filter(
                array_map('intval', explode(',', (string) $groupId)),
                fn ($id) => $id > 0
            ));
            if (empty($groupIds)) {
                $this->error('--within-group had no usable group ids.');

                return false;
            }
            $idList = implode(',', $groupIds);

            // Keep only ids that actually have a polygon. MySQL ST_Union is BINARY (not an
            // aggregate), so chain ST_Union over per-id subqueries - this avoids round-tripping
            // each (large) polygon out to WKT and back through a giant query string.
            $validIds = DB::table('groups')->whereIn('id', $groupIds)->whereNotNull('polyindex')
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (empty($validIds)) {
                $this->error("None of the --within-group ids [{$idList}] have a usable polyindex polygon.");

                return false;
            }
            $count = count($validIds);
            $expr = '(SELECT polyindex FROM `groups` WHERE id = ' . array_pop($validIds) . ')';
            foreach (array_reverse($validIds) as $id) {
                $expr = 'ST_Union((SELECT polyindex FROM `groups` WHERE id = ' . $id . '), ' . $expr . ')';
            }
            $wkt = DB::selectOne('SELECT ST_AsText(' . $expr . ') AS u')?->u;
            if (!$wkt) {
                $this->error("ST_Union of --within-group polygons [{$idList}] returned NULL.");

                return false;
            }
            $this->info("Resolved --within-group=[{$idList}] to the union of {$count} area polygon(s).");

            return $wkt;
        }

        return null;
    }
}
