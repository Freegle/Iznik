<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
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
                            {--limit=500 : Max posts to initialise/advance this run}
                            {--msgid= : Restrict the whole run to a single message ID (controlled testing)}
                            {--within-poly= : Restrict the run to posts whose origin lies within this WKT polygon (area testing)}
                            {--within-group= : Restrict the run to posts within this group ID\'s area (area testing; resolved to its polygon)}';

    protected $description = 'Maintain rippling-out reach (rippling_reach) for active posts';

    public function handle(ExpandService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
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
            // Resolve to the group's area polygon (the same polyindex the cross-post step tests).
            $wkt = DB::table('groups')
                ->where('id', (int) $groupId)
                ->whereNotNull('polyindex')
                ->value(DB::raw('ST_AsText(polyindex)'));
            if (!$wkt) {
                $this->error("Group {$groupId} has no usable polyindex polygon.");

                return false;
            }
            $this->info("Resolved --within-group={$groupId} to its area polygon.");

            return $wkt;
        }

        return null;
    }
}
