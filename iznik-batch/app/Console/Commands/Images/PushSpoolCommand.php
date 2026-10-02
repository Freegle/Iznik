<?php

namespace App\Console\Commands\Images;

use App\Services\ImageStore\ObjectStoreUnavailable;
use App\Services\ImageStore\SpoolPusherService;
use Illuminate\Console\Command;

/**
 * Move completed uploads from tusd's local spool to the object store.
 *
 * Scheduled every minute in production (routes/console.php) once
 * freegle.image_store.enabled is on. Safe to run by hand at any time.
 *
 *   php artisan images:push-spool --dry-run
 */
class PushSpoolCommand extends Command
{
    protected $signature = 'images:push-spool
                            {--limit= : Uploads to push or clean up in this pass (default from config)}
                            {--dry-run : Report what would happen without touching anything}';

    protected $description = 'Push completed tusd uploads from the local spool to the object store';

    public function handle(): int
    {
        // Checked from config BEFORE the disk is resolved: resolving a local
        // disk creates its root, so an unmounted /spool would otherwise be
        // created empty inside the container and the pass would report a
        // quiet success over nothing.
        $root = (string) config('filesystems.disks.tusd-spool.root');

        if ($root === '' || ! is_dir($root)) {
            $this->error("The tusd spool {$root} is not a directory. Is the tusd-spool volume mounted?");

            return Command::FAILURE;
        }

        // Built here rather than injected: the container resolves the
        // service's nullable Filesystem parameter to the DEFAULT disk, and the
        // pass would silently scan storage/app instead of the spool.
        $pusher = new SpoolPusherService();

        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('freegle.image_store.push_limit', 500);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY RUN] nothing will be written or deleted');
        }

        $stats = $pusher->push($limit, $dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $stats['scanned']],
                ['Pushed', $stats['pushed']],
                ['Already in store', $stats['present']],
                ['Waiting (grace)', $stats['recent']],
                ['Incomplete', $stats['incomplete']],
                ['Unreadable', $stats['unreadable']],
                ['Abandoned, removed', $stats['abandoned']],
                ['Failed', $stats['failed']],
                ['Bytes', $stats['bytes']],
            ]
        );

        if ($stats['unavailable'] !== null) {
            // Sentry, via the exception handler: the log stack is file-only.
            $this->error("The object store is unavailable; the pass stopped and the spool is untouched. Uploads are served from the spool until it is back. {$stats['unavailable']}");
            report(new ObjectStoreUnavailable('images:push-spool: ' . $stats['unavailable']));

            return Command::FAILURE;
        }

        if ($stats['failed'] > 0) {
            $this->error("{$stats['failed']} upload(s) could not be pushed; they stay in the spool for the next pass.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
