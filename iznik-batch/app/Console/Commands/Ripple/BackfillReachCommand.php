<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:backfill — one-off (re-runnable) drain that seeds a rippling_reach row for every
 * LIVE post which is eligible to ripple but has none.
 *
 * Rippling only ever initialises reach for posts arriving on/after the go-live cutoff
 * (freegle.ripple.enabled_at), so every post whose messages_spatial.arrival predates go-live
 * (~34k on prod at time of writing) has no reach row and would never ripple. This command seeds
 * those rows by reusing ExpandService::backfill() -> initialiseNew() with the arrival cutoff
 * lifted, so a backfilled post is identical to a natively-initialised one.
 *
 * COST: seeding is NOT a cheap insert. initialiseNew computes each post's reach polygon inline on
 * the routing/isochrone server (deduped by blurred origin, fanned out at
 * freegle.ripple.compute_concurrency) and cross-posts the post into its tick-0 groups. The routing
 * server is the throughput limiter (dense-area isochrones take ~14-29s on a 7.3GB UK graph), so
 * this command is shardable: run several instances over DISJOINT msgid slices with --shards N
 * --shard i. Total concurrent routing load ~= shards x compute_concurrency — keep it within the
 * routing server's headroom (see class docs / the deploy report for recommended degrees).
 *
 * Safe alongside the expand cron and other shards: each shard takes its OWN Cache lock
 * ('ripple:backfill:{seed|recompute}[:shardN-i]', never 'ripple:expand:run') and every write is one
 * row at a time (Galera-safe, inherited from initialiseNew). Idempotent/resumable — re-run until
 * drained. --dry-run samples without writing; --limit caps a single invocation; --within-poly
 * stages a geographic subset.
 *
 * TWO-PHASE backfill (to avoid a multi-hour routing-bound drain leaving posts dark meanwhile):
 *   1. A quick geometry pass (run separately) seeds every post a cheap placeholder ("DPA") reach =
 *      its group area, so nothing is un-rippled/reply-dark immediately. Those rows are
 *      status='stopped', schedule NULL.
 *   2. --recompute then upgrades those placeholders to real routed reach IN PLACE (upsert — never a
 *      gap), at a gentle pace. Reach is deterministic per blurred origin, so co-located posts reuse
 *      an already-computed reach (freegle.ripple.reuse_reach) instead of re-routing — which removes
 *      the bulk of routing calls where posters share a postcode/home.
 */
class BackfillReachCommand extends Command
{
    use GracefulShutdown;

    /** SRID of messages_spatial.point (Web Mercator); matches ExpandService::SRID. */
    private const SRID = 3857;

    protected $signature = 'ripple:backfill
                            {--dry-run : Sample and report what would be seeded, writing nothing}
                            {--limit=0 : Max posts to seed this invocation (0 = drain the whole backlog)}
                            {--batch=50 : Posts processed per internal batch. initialiseNew computes ALL schedules before writing any, so a large batch stalls the routing host before any row lands; 50 keeps each batch small and the loop draining}
                            {--shards= : Total number of parallel shards (partition candidates by msgid % shards). Omit for a single unsharded run}
                            {--shard= : This instance\'s shard index, 0..shards-1 (required with --shards)}
                            {--within-poly= : Restrict to posts whose origin lies within this WKT polygon (staged / area backfill)}
                            {--recompute : Replace placeholder (DPA / group-area) reach seeds — status=stopped, schedule NULL — with real routing-computed reach, in place. Use after the quick geometry seed to upgrade the placeholders at a gentle pace}';

    protected $description = 'Backfill: seed rippling reach for live posts that predate go-live and have no reach row';

    public function handle(ExpandService $service): int
    {
        $this->registerShutdownHandlers();

        [$shardCount, $shardIndex] = $this->resolveSharding();
        if ($shardCount === false) {
            return Command::FAILURE;
        }

        $withinPoly = $this->option('within-poly') !== null ? trim((string) $this->option('within-poly')) : null;
        if ($withinPoly === '') {
            $this->error('--within-poly was empty.');

            return Command::FAILURE;
        }

        // Gated exactly like ExpandService::process(): an unscoped run does nothing while global
        // rippling is off, but a within-poly scope is still allowed through (experiment path).
        if (!config('freegle.ripple.enabled') && $withinPoly === null) {
            $this->warn('Rippling is disabled (RIPPLE_ENABLED=false) and no --within-poly scope given; nothing to do.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        // DISTINCT single-instance lock per shard — deliberately NOT 'ripple:expand:run', so the
        // backfill and the expand cron never block or deadlock each other, and disjoint shards run
        // concurrently. Two instances of the SAME shard must not overlap (they'd re-seed the same
        // slice), so the second exits cleanly while the first holds the shard lock. --dry-run is
        // exempt: it writes nothing and an operator must be able to sample alongside a live drain.
        $mode = $this->option('recompute') ? 'recompute' : 'seed';
        $lockName = $shardCount !== null
            ? "ripple:backfill:{$mode}:shard{$shardCount}-{$shardIndex}"
            : "ripple:backfill:{$mode}";
        $lock = $dryRun ? null : Cache::lock($lockName, 3600);
        if ($lock !== null && !$lock->get()) {
            Log::info("ripple:backfill skipped: another run already holds {$lockName}");
            $this->info('Another ripple:backfill run for this shard is in progress; exiting.');

            return Command::SUCCESS;
        }

        try {
            return $this->runBackfill($service, $dryRun, $withinPoly, $shardCount, $shardIndex, (bool) $this->option('recompute'));
        } finally {
            $lock?->release();
        }
    }

    /**
     * Validate --shards/--shard. Returns [shardCount|null, shardIndex|null] (both null = unsharded)
     * or [false, null] on a usage error (caller aborts).
     *
     * @return array{0: int|null|false, 1: int|null}
     */
    private function resolveSharding(): array
    {
        $shards = $this->option('shards');
        $shard = $this->option('shard');

        if ($shards === null && $shard === null) {
            return [null, null];
        }
        if ($shards === null || $shard === null) {
            $this->error('--shards and --shard must be given together.');

            return [false, null];
        }

        $shardCount = (int) $shards;
        $shardIndex = (int) $shard;
        if ($shardCount < 1) {
            $this->error('--shards must be >= 1.');

            return [false, null];
        }
        if ($shardIndex < 0 || $shardIndex >= $shardCount) {
            $this->error("--shard must be in the range 0..".($shardCount - 1)." for --shards={$shardCount}.");

            return [false, null];
        }
        if ($shardCount === 1) {
            // A single shard is just an unsharded run; drop the predicate so it uses the plain lock.
            return [null, null];
        }

        return [$shardCount, $shardIndex];
    }

    private function runBackfill(ExpandService $service, bool $dryRun, ?string $withinPoly, ?int $shardCount, ?int $shardIndex, bool $recompute = false): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $batch = max(1, (int) $this->option('batch'));

        $shardLabel = $shardCount !== null ? " [shard {$shardIndex}/{$shardCount}]" : '';
        $total = $this->countCandidates($withinPoly, $shardCount, $shardIndex, $recompute);
        $target = $limit > 0 ? min($total, $limit) : $total;

        $noun = $recompute ? 'placeholder (DPA) reach seed(s) to recompute' : 'live post(s) with no reach row';
        $this->info(sprintf(
            '%s%d %s%s%s.',
            $dryRun ? '[DRY RUN] ' : '',
            $total,
            $noun,
            $shardLabel,
            $withinPoly !== null ? ' within the given polygon' : ''
        ));

        if ($total === 0) {
            $this->info($recompute ? 'Nothing to recompute.' : 'Nothing to backfill.');

            return Command::SUCCESS;
        }

        // DRY RUN: a single bounded sample, no loop. A dry run writes nothing, so its candidates
        // never drain and a drain-loop would spin forever; instead we run one batch through the
        // real (dry) seed path and report the split, plus the full target for context.
        if ($dryRun) {
            $sample = $limit > 0 ? min($batch, $limit) : $batch;
            $stats = $service->backfill(true, $sample, $withinPoly, $shardCount, $shardIndex, $recompute);
            $verb = $recompute ? 'recompute' : 'seed';
            $this->info(sprintf(
                '[DRY RUN] Sampled up to %d candidate(s): would %s %d (of which %d reused an existing co-located reach), would skip %d (unreachable / null-arrival). Re-run without --dry-run to %s all %d.',
                min($sample, $total),
                $verb,
                $stats['initialized'],
                $stats['reused'] ?? 0,
                $stats['skipped'],
                $verb,
                $target
            ));

            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($target);
        $bar->start();

        $seeded = 0;
        $rippledIn = 0;
        $memberships = 0;
        $errors = 0;
        $reused = 0;
        $stopped = false;
        while (true) {
            if ($limit > 0 && $seeded >= $limit) {
                break;
            }
            $thisBatch = $limit > 0 ? min($batch, $limit - $seeded) : $batch;
            $stats = $service->backfill(false, $thisBatch, $withinPoly, $shardCount, $shardIndex, $recompute);

            $seeded += $stats['initialized'];
            $rippledIn += $stats['rippled_in'];
            $memberships += $stats['memberships_added'];
            $errors += $stats['errors'];
            $reused += $stats['reused'] ?? 0;
            $bar->advance($stats['initialized']);

            // Stop when a batch seeds nothing: the only candidates left are permanently ineligible
            // THIS run (off-graph origin, reply-saturated, or null arrival). They stay in the
            // LEFT JOIN ... IS NULL candidate set, so without this the loop would never terminate.
            if ($stats['initialized'] === 0) {
                break;
            }
            if ($this->shouldStop()) {
                $stopped = true;
                break;
            }
        }

        $bar->finish();
        $this->newLine();
        if ($stopped) {
            $this->warn('Graceful shutdown requested — stopping (safe to re-run to continue).');
        }

        $remaining = $this->countCandidates($withinPoly, $shardCount, $shardIndex, $recompute);
        $this->info(sprintf(
            '%s %d reach row(s)%s (%d reused a co-located reach — no routing call); rippled into %d group-membership(s); auto-joined poster to %d group(s); errors: %d. %d candidate row(s) still %s%s.',
            $recompute ? 'Recomputed' : 'Seeded',
            $seeded,
            $shardLabel,
            $reused,
            $rippledIn,
            $memberships,
            $errors,
            $remaining,
            $recompute ? 'as placeholders' : 'without reach',
            $remaining > 0
                ? ' (off-graph / null-arrival, or beyond --limit — re-run to continue)'
                : ''
        ));

        Log::info('ripple:backfill complete', [
            'mode' => $recompute ? 'recompute' : 'seed',
            'shard' => $shardCount !== null ? "{$shardIndex}/{$shardCount}" : null,
            'seeded' => $seeded,
            'reused' => $reused,
            'rippled_in' => $rippledIn,
            'memberships_added' => $memberships,
            'errors' => $errors,
            'remaining' => $remaining,
        ]);

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Count live posts (messages_spatial) with no rippling_reach row — the backfill's candidate
     * set (an upper bound; some are later skipped as ineligible). Drives the progress-bar total
     * and the closing "still remaining" figure. Honours the same msgid shard partition as the
     * seed selection so the count matches this instance's slice. Plain non-locking read.
     */
    private function countCandidates(?string $withinPoly, ?int $shardCount, ?int $shardIndex, bool $recompute = false): int
    {
        // Recompute counts the placeholder (DPA) seeds still to upgrade (status='stopped', schedule
        // NULL); the default counts live posts with no reach row at all (anti-join).
        $q = $recompute
            ? DB::table('messages_spatial as ms')
                ->join('rippling_reach as rr', 'rr.msgid', '=', 'ms.msgid')
                ->where('rr.status', 'stopped')
                ->whereNull('rr.schedule')
            : DB::table('messages_spatial as ms')
                ->leftJoin('rippling_reach as rr', 'rr.msgid', '=', 'ms.msgid')
                ->whereNull('rr.msgid');
        if ($withinPoly !== null) {
            $q->whereRaw('ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), ms.point)', [$withinPoly]);
        }
        if ($shardCount !== null && $shardIndex !== null) {
            $q->whereRaw('(ms.msgid % ?) = ?', [$shardCount, $shardIndex]);
        }

        return (int) $q->distinct()->count('ms.msgid');
    }
}
