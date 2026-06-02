<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Traits\FeatureFlags;
use App\Services\UnifiedDigestService;
use Illuminate\Console\Command;

class SendUnifiedDigestCommand extends Command
{
    use FeatureFlags;
    use PreventsOverlapping;

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

        if (! in_array($mode, [UnifiedDigestService::MODE_DAILY, UnifiedDigestService::MODE_IMMEDIATE])) {
            $this->error("Invalid mode '{$mode}'. Must be 'daily' or 'immediate'.");
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
        // mail:chat:user2user pattern: sleep(1) when nothing to do, keep
        // looping until max-iterations is hit. The next cron tick takes
        // over from there.
        // Immediate mode reports per-group counters (groups_processed,
        // no_new_posts_groups); daily mode reports per-user ones
        // (no_new_posts). Initialise both so either summary table below can
        // read its keys regardless of which mode ran.
        $stats = ['groups_processed' => 0, 'users_processed' => 0, 'emails_sent' => 0, 'no_new_posts_groups' => 0, 'no_new_posts' => 0, 'errors' => 0];

        for ($i = 0; $i < $maxIterations; $i++) {
            $r = $service->sendDigests($mode, $userId, $limit, $dryRun, $groupId, $shard, $shards);

            // Daily mode returns a different stat shape — match keys best-effort.
            foreach (['groups_processed', 'users_processed', 'emails_sent', 'no_new_posts_groups', 'no_new_posts', 'errors'] as $k) {
                if (isset($r[$k])) {
                    $stats[$k] += $r[$k];
                }
            }

            // Sleep briefly between idle iterations so we don't hammer
            // the DB with empty queries. Active iterations roll straight
            // into the next without a sleep.
            if (($r['emails_sent'] ?? 0) === 0 && $i + 1 < $maxIterations) {
                sleep(1);
            }
        }

        $this->newLine();

        if ($mode === UnifiedDigestService::MODE_IMMEDIATE) {
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
        $shards = max(1, (int) $this->option('shards'));
        if ($shards === 1) {
            return null; // single-shard runs use the default unsuffixed lock
        }
        $shard = (int) $this->option('shard');
        return "shard-{$shard}-of-{$shards}";
    }
}
