<?php

namespace App\Console\Commands\FirstReply;

use App\Services\FirstReply\EngagementService;
use App\Services\FirstReply\Rollout;
use Illuminate\Console\Command;

/**
 * firstreply:engage - Freegle talks to people whose posts nobody has answered.
 *
 * See EngagementService for what it says and, more importantly, what it refuses
 * to say.
 */
class EngageCommand extends Command
{
    protected $signature = 'firstreply:engage
                            {--dry-run : Work out what would be sent without sending it}';

    protected $description = 'Send Freegle chat prompts about posts with no replies';

    public function handle(EngagementService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info(Rollout::describe());
        $stats = $service->run($dryRun);

        $detail = collect($stats)
            ->except(['considered', 'sent', 'skipped'])
            ->map(fn ($count, $kind) => "{$kind}={$count}")
            ->implode(' ');

        $this->info(sprintf(
            '%sengage: considered %d, sent %d, skipped %d%s',
            $dryRun ? '[DRY RUN] ' : '',
            $stats['considered'],
            $stats['sent'],
            $stats['skipped'],
            $detail === '' ? '' : " ({$detail})"
        ));

        return Command::SUCCESS;
    }
}
