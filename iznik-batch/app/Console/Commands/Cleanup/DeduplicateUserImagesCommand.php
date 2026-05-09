<?php

namespace App\Console\Commands\Cleanup;

use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicateUserImagesCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'cleanup:user-images
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Remove duplicate user profile images, keeping only the most recent per user';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no data will be deleted.');
        }

        return $this->runWithLogging(function () use ($dryRun) {
            $dups = DB::select(
                'SELECT userid, MAX(id) AS max_id, COUNT(*) AS cnt
                 FROM users_images
                 GROUP BY userid
                 HAVING cnt > 1'
            );

            $deleted = 0;

            foreach ($dups as $dup) {
                if ($dryRun) {
                    $deleted += $dup->cnt - 1;
                } else {
                    $deleted += DB::table('users_images')
                        ->where('userid', $dup->userid)
                        ->where('id', '<', $dup->max_id)
                        ->delete();
                }
            }

            $verb = $dryRun ? 'Would remove' : 'Removed';
            $this->info("{$verb} {$deleted} duplicate user image(s) across " . count($dups) . ' user(s).');

            return Command::SUCCESS;
        });
    }
}
