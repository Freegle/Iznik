<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot repair for rippling_reach rows whose inner bound is missing or uselessly
 * small — the town-core-fragment inners the routing grid's erosion produced for rural
 * reaches, which sent ~58% of browse candidates to the full polygon test and saturated
 * db3 (Aug 2026). Re-derives the inner from the stored reach grid via
 * ReachBoundsService::ensureUsefulInner, one row at a time, paced for Galera.
 *
 * Safe to re-run (idempotent — a useful inner is never rewritten). Degraded
 * completed-post rows (POINT outer) are skipped. Run with --dry-run first on
 * production to see the candidate count.
 */
class BackfillInnerBoundsCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'ripple:backfill-inner-bounds
                            {--dry-run : Report how many rows would be fixed without changing anything}
                            {--limit=0 : Max rows to fix (0 = no limit)}
                            {--sleep-ms=200 : Pause between row updates, to pace Galera replication}
                            {--min-ratio= : Override the inner/outer area share below which an inner is re-derived}';

    protected $description = 'Re-derive missing or uselessly small rippling_reach inner bounds from the stored reach grid';

    public function handle(ReachBoundsService $bounds): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');
            $limit = max(0, (int) $this->option('limit'));
            $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;
            $minRatio = $this->option('min-ratio') !== null && is_numeric($this->option('min-ratio'))
                ? (float) $this->option('min-ratio')
                : ReachBoundsService::INNER_MIN_AREA_RATIO;

            $stats = ['scanned' => 0, 'candidates' => 0, 'derived' => 0, 'nulled' => 0, 'skipped' => 0, 'errors' => 0];
            $lastId = 0;

            // PK-chunked scan carrying no GIS work, so one row's invalid geometry can
            // only fail its own per-row check, never a whole chunk.
            while (true) {
                $msgids = DB::table('rippling_reach')
                    ->where('msgid', '>', $lastId)
                    ->orderBy('msgid')
                    ->limit(500)
                    ->pluck('msgid');

                if ($msgids->isEmpty()) {
                    break;
                }

                foreach ($msgids as $msgid) {
                    $lastId = (int) $msgid;
                    $stats['scanned']++;

                    try {
                        // The reach itself is a stored grid, so the health
                        // ratio is measured against OUTER_BOUND - a buffered
                        // simplification of the reach, so a slight superset:
                        // the ratio reads marginally lower than the old
                        // inner/polygon share, which errs toward re-deriving,
                        // and ensureUsefulInner never rewrites a useful inner.
                        // keep-raw: ST_GeometryType/ST_Area GIS expressions per single row
                        $row = DB::selectOne(
                            'SELECT ST_GeometryType(outer_bound) AS outer_type,
                                    inner_bound IS NULL AS missing,
                                    COALESCE(ST_Area(inner_bound) / NULLIF(ST_Area(outer_bound), 0), 0) AS ratio
                               FROM rippling_reach
                              WHERE msgid = ? AND outer_bound IS NOT NULL',
                            [$lastId]
                        );
                    } catch (\Throwable) {
                        // Unmeasurable geometry: let ensureUsefulInner's own ladder decide.
                        $row = null;
                        $stats['errors']++;
                    }

                    if ($row !== null
                        && ($row->outer_type === 'POINT'
                            || (!(int) $row->missing && (float) $row->ratio >= $minRatio))) {
                        usleep(10000);

                        continue;
                    }

                    $stats['candidates']++;

                    if ($dryRun) {
                        usleep(10000);

                        continue;
                    }

                    $outcome = $bounds->ensureUsefulInner($lastId, $minRatio);
                    $stats[$outcome === 'kept' ? 'skipped' : $outcome]++;

                    if ($limit > 0 && ($stats['derived'] + $stats['nulled']) >= $limit) {
                        break 2;
                    }
                    if ($sleepUs > 0) {
                        usleep($sleepUs);
                    }
                }

                $this->output->write("\rScanned {$stats['scanned']}, candidates {$stats['candidates']}...");
            }
            $this->output->writeln('');

            if ($dryRun) {
                $this->info("[DRY RUN] {$stats['candidates']} of {$stats['scanned']} row(s) would have their inner bound re-derived. Run without --dry-run to apply.");

                return Command::SUCCESS;
            }

            $this->info(sprintf(
                'Scanned %d row(s): %d candidate(s) — %d re-derived, %d nulled (derivation unusable), %d skipped, %d unmeasurable.',
                $stats['scanned'],
                $stats['candidates'],
                $stats['derived'],
                $stats['nulled'],
                $stats['skipped'],
                $stats['errors']
            ));
            Log::info('ripple:backfill-inner-bounds complete', $stats);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
