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

        $dups = DB::select(
            'SELECT userid, MAX(id) AS max, COUNT(*) AS count FROM users_images GROUP BY userid HAVING count > 1'
        );

        $deleted = 0;

        foreach ($dups as $dup) {
            if ($dryRun) {
                $deleted += $dup->count - 1;
            } else {
                $affected = DB::delete(
                    'DELETE FROM users_images WHERE userid = ? AND id < ?',
                    [$dup->userid, $dup->max]
                );
                $deleted += $affected;
            }
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$deleted} duplicate profile images.");
        Log::info('Archive profile images complete', ['deleted' => $deleted, 'dry_run' => $dryRun]);

        return Command::SUCCESS;
    }
}
