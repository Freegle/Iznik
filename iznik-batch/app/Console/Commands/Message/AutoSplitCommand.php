<?php

namespace App\Console\Commands\Message;

use App\Services\AutoSplit\AutoSplitService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Split new native OFFERs that list several separate things into item lists, photos included.
 * Does nothing unless AUTOSPLIT_MODE is "shadow" or "on". docs/developers/reference/auto-split.md.
 */
#[AsCommand(name: 'messages:auto-split')]
class AutoSplitCommand extends Command
{
    protected $signature = 'messages:auto-split {--limit=20 : Posts to decide this run}';

    protected $description = 'Turn new posts of several items into item lists, with each photo on its item';

    public function handle(AutoSplitService $service): int
    {
        $tally = $service->process(max(1, (int) $this->option('limit')));
        $this->info('Mode '.AutoSplitService::mode().': '.json_encode($tally));

        return self::SUCCESS;
    }
}
