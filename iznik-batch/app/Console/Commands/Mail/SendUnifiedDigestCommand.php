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
        $dryRun = $this->option('dry-run');

        if (! in_array($mode, [UnifiedDigestService::MODE_DAILY, UnifiedDigestService::MODE_IMMEDIATE])) {
            $this->error("Invalid mode '{$mode}'. Must be 'daily' or 'immediate'.");
            return Command::FAILURE;
        }

        // Daily mode is intentionally NOT live: V1's bulk3
        // `digest.php -i 1/2/4/8/24` crons still own daily and our
        // users_digests table doesn't exist on prod. Refuse with a
        // clear message rather than letting it fail mid-run with
        // "Base table not found". Local/CI tests run against a test
        // DB that DOES have the table via the iznik-batch migration
        // (2026_01_06_120000_create_users_digests_table.php).
        if ($mode === UnifiedDigestService::MODE_DAILY
            && ! \Illuminate\Support\Facades\Schema::hasTable('users_digests')) {
            $this->warn('Daily mode is not enabled here — V1 owns daily sends. users_digests table not present.');
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
        $this->info("Sending unified digests (mode: {$mode}, limit: {$limitLabel})...");

        if ($userId) {
            $this->info("Restricting recipients to user ID: {$userId}");
        }
        if ($groupId) {
            $this->info("Restricting to group ID: {$groupId}");
        }

        $stats = $service->sendDigests($mode, $userId, $limit, $dryRun, $groupId);

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
}
