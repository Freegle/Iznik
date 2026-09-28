<?php

namespace App\Console\Commands\Images;

use App\Services\ImageStore\LegacyMigrationService;
use Illuminate\Console\Command;

/**
 * Copy legacy uploads from the NFS share to the object store, driven by the
 * database rows that refer to them. Resumable, idempotent, never lists the
 * share and never deletes from it.
 *
 *   php artisan images:migrate-legacy --status
 *   php artisan images:migrate-legacy --time-budget=240
 *   php artisan images:migrate-legacy --source=users_images --dry-run
 *   php artisan images:migrate-legacy --verify
 *   php artisan images:migrate-legacy --reset=users_images
 */
class MigrateLegacyCommand extends Command
{
    protected $signature = 'images:migrate-legacy
                            {--source=* : Source(s) to walk (default: all; see --status)}
                            {--time-budget= : Seconds to run before stopping between chunks (default from config)}
                            {--chunk= : Rows per query (default from config)}
                            {--limit=0 : Stop after examining this many rows (0 = no limit)}
                            {--max-mbps= : Upload bandwidth cap in MB/s (default from config; 0 = none)}
                            {--dry-run : Report what would be copied without copying or moving cursors}
                            {--verify : Report referenced uploads the store lacks; copies nothing}
                            {--status : Show the cursor and counts for every source}
                            {--reset= : Start this source again from the beginning (with --verify: its verify cursor)}';

    protected $description = 'Copy legacy tusd uploads from the NFS share to the object store';

    public function handle(): int
    {
        // Built here rather than injected: the container resolves the
        // service's nullable Filesystem parameter to the DEFAULT disk.
        $migrator = new LegacyMigrationService();

        if ($this->option('status')) {
            return $this->showStatus($migrator);
        }

        try {
            $sources = LegacyMigrationService::validateSources((array) $this->option('source'));
            if ($this->option('reset') !== null) {
                $migrator->resetCursor((string) $this->option('reset'), (bool) $this->option('verify'));
                $this->info("Cursor reset for {$this->option('reset')}.");

                return Command::SUCCESS;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $budget = $this->option('time-budget') !== null
            ? (int) $this->option('time-budget')
            : (int) config('freegle.image_store.migrate_time_budget', 240);
        $chunk = $this->option('chunk') !== null
            ? (int) $this->option('chunk')
            : (int) config('freegle.image_store.migrate_chunk', 500);
        $limit = (int) $this->option('limit');

        if ($this->option('verify')) {
            return $this->verify($migrator, $sources, $budget, $chunk, $limit);
        }

        // From config, before the disk is resolved: resolving a local disk
        // creates its root, and an unmounted /images must fail loudly, not be
        // created empty and walked as if every upload were missing.
        $root = (string) config('filesystems.disks.tusd-legacy.root');

        if ($root === '' || ! is_dir($root)) {
            $this->error("The legacy upload store {$root} is not a directory. Is the NFS share bound into this container?");

            return Command::FAILURE;
        }

        $maxMbps = $this->option('max-mbps') !== null
            ? (float) $this->option('max-mbps')
            : (float) config('freegle.image_store.migrate_max_mbps', 10);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY RUN] nothing will be copied and no cursor will move');
        }

        $stats = $migrator->migrate($sources, $budget, $chunk, $limit, $maxMbps, $dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows examined', $stats['scanned']],
                ['Copied', $stats['copied']],
                ['Already in store', $stats['present']],
                ['Missing on legacy share', $stats['missing_source']],
                ['Not a tusd id', $stats['invalid']],
                ['Failed', $stats['failed']],
                ['Bytes', $stats['bytes']],
            ]
        );

        if ($stats['finished']) {
            $this->info('Every requested source is complete. Run --verify next.');
        } elseif ($stats['budget_exhausted']) {
            $this->line('Time budget used; the next run carries on from the cursor.');
        }

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function verify(LegacyMigrationService $migrator, array $sources, int $budget, int $chunk, int $limit): int
    {
        $stats = $migrator->verify($sources, $budget, $chunk, $limit);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows examined', $stats['scanned']],
                ['Present in store', $stats['present']],
                ['Missing from store', $stats['missing']],
                ['Not a tusd id', $stats['invalid']],
            ]
        );

        if ($stats['missing'] > 0) {
            $this->error("{$stats['missing']} referenced upload(s) are not in the object store:");
            foreach ($stats['missing_ids'] as $id) {
                $this->line("  {$id}");
            }
            if ($stats['missing'] > count($stats['missing_ids'])) {
                $this->line('  ... (the rest are in the log)');
            }

            return Command::FAILURE;
        }

        if ($stats['finished']) {
            $this->info('Verified: every referenced upload in the requested sources is in the object store.');
        } elseif ($stats['budget_exhausted']) {
            $this->line('Time budget used; run again to continue the verify from its cursor.');
        }

        return Command::SUCCESS;
    }

    private function showStatus(LegacyMigrationService $migrator): int
    {
        $rows = [];
        foreach ($migrator->status() as $s) {
            $rows[] = [
                $s['source'],
                $s['last_id'],
                $s['copied'],
                $s['present'],
                $s['missing_source'],
                $s['failed'],
                round($s['bytes'] / 1_000_000_000, 2),
                $s['completed_at'] ?? '-',
                $s['verify_missing'],
                $s['verify_completed_at'] ?? '-',
            ];
        }

        $this->table(
            ['Source', 'Cursor', 'Copied', 'Present', 'Missing src', 'Failed', 'GB', 'Copy done', 'Verify missing', 'Verify done'],
            $rows
        );

        return Command::SUCCESS;
    }
}
