<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Traits\FeatureFlags;
use App\Services\UnifiedDigestService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;

class SendUnifiedDigestCommand extends Command
{
    use FeatureFlags;
    use GracefulShutdown;
    use PreventsOverlapping;

    /**
     * How long an idle pass through the loop takes, at minimum.
     *
     * Chosen to match what an idle pass effectively cost before: the eligible-groups
     * query plus a one-second sleep. Paced on elapsed time rather than slept flat, so
     * that making those queries cheaper banks the saving instead of spending it on
     * polling more often. Only idle passes wait - a pass that sent something starts the
     * next one immediately.
     */
    private const IDLE_ITERATION_SECONDS = 2.0;

    /**
     * The shortest pause between idle passes, whatever the pass cost.
     *
     * Pacing purely on elapsed time has a nasty edge: a pass that overruns the period
     * leaves no remainder to wait out, so the loop would go straight round again with no
     * gap at all. The pass most likely to overrun is a slow one, and the usual reason for
     * a slow one is a database under strain - exactly when easing off matters most. This
     * floor keeps a gap there regardless.
     */
    private const IDLE_MINIMUM_PAUSE_SECONDS = 0.5;

    /**
     * How long to wait after an idle pass that took $elapsed seconds.
     *
     * Separated out so the awkward cases can be tested without having to make a real
     * pass run slowly.
     */
    public static function idlePauseSeconds(float $elapsed): float
    {
        return max(self::IDLE_MINIMUM_PAUSE_SECONDS, self::IDLE_ITERATION_SECONDS - $elapsed);
    }

    /**
     * The name and signature of the console command.
     *
     * --limit semantics:
     *   - immediate mode: caps the number of GROUPS processed per run
     *     (one row from groups_digests per iteration)
     *   - daily mode: caps the number of USERS processed per run
     *
     * --group lets a manual sanity run target a single group (immediate
     * mode only). --user works in either mode.
     */
    protected $signature = "mail:digest:unified
                            {--mode=daily : Digest mode - 'daily' or 'immediate'}
                            {--user= : Restrict recipients to one user ID (for testing)}
                            {--group= : Restrict to one group ID (immediate mode only, for testing)}
                            {--limit= : Cap per-run work — groups (immediate) or users (daily)}
                            {--shard=0 : Shard index for parallel immediate-mode workers (0..shards-1)}
                            {--shards=1 : Total number of shards (groups are partitioned by groupid MOD shards)}
                            {--max-iterations=1 : Loop sendImmediateDigests this many times within one invocation (cron passes a higher value to fill the minute between ticks)}
                            {--dry-run : Show what would be sent without actually sending}";

    /**
     * The console command description.
     */
    protected $description = "Send unified Freegle digests containing posts from all user's communities";

    private const EMAIL_TYPE = 'UnifiedDigest';

    /**
     * Execute the console command.
     */
    public function handle(UnifiedDigestService $service): int
    {
        // Check if UnifiedDigest emails are enabled for this batch system.
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("UnifiedDigest emails are not enabled in iznik-batch. Set FREEGLE_MAIL_ENABLED_TYPES in .env to include 'UnifiedDigest'.");

            return Command::SUCCESS;
        }

        // flock-based overlap prevention. Replaces Laravel's
        // ->withoutOverlapping() on the schedule which silently failed and
        // allowed six concurrent processes during the 2026-05-27 rollout.
        // The lock is auto-released on process death (LOCK_NB + flock), so
        // a crashed run can't wedge subsequent ticks.
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            return $this->doHandle($service);
        } finally {
            $this->releaseLock();
        }
    }

    protected function doHandle(UnifiedDigestService $service): int
    {
        // SIGTERM/SIGINT (and an optional abort file) flip a flag the service
        // checks between users (daily) or between groups (immediate), so a
        // long --mode=daily run can be drained cleanly without tearing a
        // per-user spool write. The 2026-06-11 manual rollout had to be
        // SIGTERM'd with no graceful path; this wires one in for next time.
        $this->registerShutdownHandlers();

        $mode = $this->option('mode');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $groupId = $this->option('group') ? (int) $this->option('group') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $shard = (int) $this->option('shard');
        $shards = max(1, (int) $this->option('shards'));
        $dryRun = $this->option('dry-run');

        if ($shard < 0 || $shard >= $shards) {
            $this->error("--shard must be in [0, {$shards}); got {$shard}.");
            return Command::FAILURE;
        }

        if (! in_array($mode, [UnifiedDigestService::MODE_DAILY, UnifiedDigestService::MODE_IMMEDIATE, UnifiedDigestService::MODE_REACH])) {
            $this->error("Invalid mode '{$mode}'. Must be 'daily', 'immediate' or 'reach'.");
            return Command::FAILURE;
        }

        // Daily mode is gated at the recipient level by
        // FREEGLE_DIGEST_DAILY_ALLOWLIST (default empty = nobody; V1's bulk3
        // `digest.php -i 24` cron still owns daily for everyone else). This
        // is just a defensive guard: if the users_digests table hasn't been
        // migrated in this environment, refuse with a clear message rather
        // than failing mid-run with "Base table not found". The table is
        // created by 2026_01_06_120000_create_users_digests_table.php.
        if ($mode === UnifiedDigestService::MODE_DAILY
            && ! \Illuminate\Support\Facades\Schema::hasTable('users_digests')) {
            $this->warn('Daily mode skipped — users_digests table not present (migration not run here).');
            return Command::SUCCESS;
        }

        if ($groupId && $mode !== UnifiedDigestService::MODE_IMMEDIATE) {
            $this->error('--group is only supported with --mode=immediate.');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $limitLabel = $limit !== null ? (string) $limit : 'unlimited';
        $shardLabel = $shards > 1 ? " shard={$shard}/{$shards}" : '';
        $maxIterations = max(1, (int) $this->option('max-iterations'));
        $iterLabel = $maxIterations > 1 ? " iterations≤{$maxIterations}" : '';
        $this->info("Sending unified digests (mode: {$mode}, limit: {$limitLabel}{$shardLabel}{$iterLabel})...");

        if ($userId) {
            $this->info("Restricting recipients to user ID: {$userId}");
        }
        if ($groupId) {
            $this->info("Restricting to group ID: {$groupId}");
        }

        // Iterate within one invocation so the worker keeps the wall clock
        // busy instead of exiting after one pass and idling until the next
        // cron tick. The flock prevents two ticks from overlapping, so
        // looping inside is the right place to amortise process startup.
        //
        // Don't break on an idle iteration — new messages can arrive at
        // any time during our run, and another shard may be processing
        // one of our groups (no — partitions are disjoint, but messages
        // are still arriving from outside the digest system). Match the
        // mail:chat:user2user pattern: hold idle passes to a fixed period
        // (IDLE_ITERATION_SECONDS) rather than racing round the loop, and keep
        // looping until max-iterations is hit. The next cron tick takes
        // over from there.
        // Immediate mode reports per-group counters (groups_processed,
        // no_new_posts_groups); daily mode reports per-user ones
        // (no_new_posts). Initialise both so either summary table below can
        // read its keys regardless of which mode ran.
        $stats = ['groups_processed' => 0, 'users_processed' => 0, 'posts_processed' => 0, 'emails_sent' => 0, 'no_new_posts_groups' => 0, 'no_new_posts' => 0, 'errors' => 0];

        $shouldStop = fn () => $this->shouldStop();

        for ($i = 0; $i < $maxIterations; $i++) {
            // hrtime, not microtime: this measures how long the pass took, and the
            // system clock can step (NTP, a VM resuming) in a way that would make that
            // measurement nonsense. hrtime only ever moves forward.
            $iterationStart = hrtime(true);

            $r = $service->sendDigests($mode, $userId, $limit, $dryRun, $groupId, $shard, $shards, $shouldStop);

            // Daily mode returns a different stat shape — match keys best-effort.
            foreach (['groups_processed', 'users_processed', 'posts_processed', 'emails_sent', 'no_new_posts_groups', 'no_new_posts', 'errors'] as $k) {
                if (isset($r[$k])) {
                    $stats[$k] += $r[$k];
                }
            }

            // Service signalled a clean stop (SIGTERM/SIGINT/abort-file) —
            // break the outer max-iterations loop too rather than starting
            // a fresh batch.
            if (!empty($r['stopped'])) {
                $this->info('Shutdown signal received — stopped between batches.');
                break;
            }

            // Hold idle iterations to a fixed period so we don't hammer the DB with
            // empty queries. Active iterations roll straight into the next without a
            // wait, because immediate mail is meant to be immediate.
            //
            // This paces on ELAPSED TIME rather than sleeping a flat second, which
            // matters as soon as the queries get cheaper: a flat sleep leaves the poll
            // rate free to rise as the work shrinks, so a saving in the query is partly
            // spent on running it more often. Sleeping the remainder of the period
            // instead holds the rate steady whatever the queries cost.
            if (($r['emails_sent'] ?? 0) === 0 && $i + 1 < $maxIterations) {
                $elapsed = (hrtime(true) - $iterationStart) / 1_000_000_000;
                usleep((int) (self::idlePauseSeconds($elapsed) * 1_000_000));
            }
        }

        $this->newLine();

        if ($mode === UnifiedDigestService::MODE_REACH) {
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Posts Processed', $stats['posts_processed']],
                    ['Emails Sent', $stats['emails_sent']],
                    ['Errors', $stats['errors']],
                ]
            );
        } elseif ($mode === UnifiedDigestService::MODE_IMMEDIATE) {
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Groups Processed', $stats['groups_processed']],
                    ['Users Touched', $stats['users_processed']],
                    ['Emails Sent', $stats['emails_sent']],
                    ['Groups With No New Posts', $stats['no_new_posts_groups']],
                    ['Errors', $stats['errors']],
                ]
            );
        } else {
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Users Processed', $stats['users_processed']],
                    ['Emails Sent', $stats['emails_sent']],
                    ['No New Posts', $stats['no_new_posts']],
                    ['Errors', $stats['errors']],
                ]
            );
        }

        if ($stats['errors'] > 0) {
            $this->warn("There were {$stats['errors']} errors. Check logs for details.");
        }

        return Command::SUCCESS;
    }

    /**
     * Customise the lock filename so each immediate-mode shard gets its
     * own flock — shards must not block each other. Read directly from
     * the option layer; the values are parsed before handle() runs and
     * before the trait calls acquireLock().
     */
    protected function lockKeySuffix(): ?string
    {
        // Key the flock by MODE as well as shard. daily, immediate and reach are now
        // all sharded, and a bare "shard-0-of-8" would make daily shard 0 and immediate
        // shard 0 share one lock file and block each other. Mode-keying keeps every
        // (mode, shard) partition on its own flock so no two ever contend.
        $mode = (string) $this->option('mode');
        $shards = max(1, (int) $this->option('shards'));
        if ($shards === 1) {
            return $mode; // single-shard run: mode-specific lock (e.g. an ad-hoc --mode=daily)
        }
        $shard = (int) $this->option('shard');
        return "{$mode}-shard-{$shard}-of-{$shards}";
    }
}
