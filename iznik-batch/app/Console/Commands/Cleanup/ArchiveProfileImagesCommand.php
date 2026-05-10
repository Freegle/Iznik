<?php

namespace App\Console\Commands\Cleanup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveProfileImagesCommand extends Command
{
    protected $signature = 'cleanup:archive-profile-images
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Delete older duplicate profile images, keeping only the most recent per user';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        $this->info('Archiving duplicate profile images...');

        // V1 (cron/archive_attachments.php) groups by userid. The bound NULL in the
        // GROUP BY result key produces `WHERE userid = NULL`, which is always false
        // in SQL — so per-user duplicates are removed but rows where userid IS NULL
        // are never reached. Those rows accumulate (placeholder gravatar/default
        // avatar entries from upload paths that didn't set userid) and must be
        // cleaned with an explicit `IS NULL` predicate.
        $dups = DB::select(
            'SELECT userid, MAX(id) AS max, COUNT(*) AS count
             FROM users_images
             WHERE userid IS NOT NULL
             GROUP BY userid HAVING count > 1'
        );

        $duplicatesDeleted = 0;

        foreach ($dups as $dup) {
            if ($dryRun) {
                $duplicatesDeleted += $dup->count - 1;
            } else {
                $affected = DB::delete(
                    'DELETE FROM users_images WHERE userid = ? AND id < ?',
                    [$dup->userid, $dup->max]
                );
                $duplicatesDeleted += $affected;
            }
        }

        // Delete orphan rows (userid IS NULL) one at a time by primary key.
        // Per-row deletion avoids Galera gap-lock issues on a large WHERE-driven
        // single-statement DELETE — the same pattern used by EngageUpdateService.
        $orphanIds = DB::table('users_images')
            ->whereNull('userid')
            ->orderBy('id')
            ->pluck('id');

        $orphansDeleted = 0;

        foreach ($orphanIds as $id) {
            if ($dryRun) {
                $orphansDeleted++;
                continue;
            }
            DB::table('users_images')->where('id', $id)->delete();
            $orphansDeleted++;
        }

        $total = $duplicatesDeleted + $orphansDeleted;
        $verb  = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$total} profile image(s): {$duplicatesDeleted} duplicate, {$orphansDeleted} orphan (NULL userid).");
        Log::info('Archive profile images complete', [
            'duplicates_deleted' => $duplicatesDeleted,
            'orphans_deleted'    => $orphansDeleted,
            'total'              => $total,
            'dry_run'            => $dryRun,
        ]);

        return Command::SUCCESS;
    }
}
