<?php

namespace App\Console\Commands\Stories;

use App\Services\StoriesService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stories:send-to-central')]
class SendStoriesToCentralCommand extends Command
{
    protected $signature = 'stories:send-to-central
                            {--dry-run : Preview without sending}';

    protected $description = 'Send unreviewed stories to central team for newsletter voting';

    public function handle(StoriesService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $count = $service->sendToCentral($dryRun);

        $prefix = $dryRun ? '[DRY RUN] Would send' : 'Sent';
        $this->info("{$prefix} {$count} story(-ies) to central.");

        return self::SUCCESS;
    }
}
