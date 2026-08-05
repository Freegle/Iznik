<?php

namespace App\Console\Commands\FirstReply;

use App\Services\FirstReply\ScoutService;
use Illuminate\Console\Command;

/**
 * firstreply:scout - tell a few likely-interested people about posts nobody has
 * replied to.
 *
 * See ScoutService for what "likely-interested" means and why the list is
 * deliberately tiny.
 */
class ScoutCommand extends Command
{
    protected $signature = 'firstreply:scout
                            {--dry-run : Pick and report scouts without mailing anyone}';

    protected $description = 'Notify likely-interested members about posts with no replies';

    public function handle(ScoutService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = $service->run($dryRun);

        $this->info(sprintf(
            '%sscouts: considered %d posts, scouted %d, mailed %d',
            $dryRun ? '[DRY RUN] ' : '',
            $stats['considered'],
            $stats['posts_scouted'],
            $stats['mailed']
        ));

        return Command::SUCCESS;
    }
}
