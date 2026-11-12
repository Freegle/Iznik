<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxIdeasService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Bring each idea's status in line with the experiment it became. Hourly. An idea whose experiment
 * has started is being tried; one whose experiment has ended is tried; one whose experiment ended
 * without a decision goes back to waiting, until it has had its tries. The experiment engine never
 * calls this: it only reads the experiment table. docs/developers/reference/ux-ideas.md.
 */
#[AsCommand(name: 'ux:ideas-sync')]
class IdeasSyncCommand extends Command
{
    protected $signature = 'ux:ideas-sync';

    protected $description = 'Bring the status of each queued or running UX idea in line with its experiment';

    public function handle(UxIdeasService $service): int
    {
        $r = $service->syncWithExperiments();
        $this->info(sprintf('Ideas: %d running, %d done, %d back to open.', $r['running'], $r['done'], $r['back_to_open']));

        return self::SUCCESS;
    }
}
