<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use App\Services\TrashNothing\Sync\UserChangesSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Gives accounts with no TN user id the one TN's user-changes feed names for them. Run it before
 * anything else meets those members: only a TN id says who someone is, so a member whose account
 * has none gets a new account, and is split from their history, on their next API post or partner
 * call. Reports only, unless --commit. See UserChangesSyncer::backfillTnUserIds().
 *
 * Prints counts only: never a name, username or address.
 */
#[AsCommand(name: 'tn:backfill-user-ids')]
class TNBackfillUserIdsCommand extends Command
{
    protected $signature = 'tn:backfill-user-ids
                            {--from= : Read the feed from this date}
                            {--to= : Read the feed up to this date (default: now)}
                            {--commit : Write. Without it, report what would be stamped and write nothing}';

    protected $description = 'Give accounts with no TN user id the one TN\'s user-changes feed names for them';

    public function handle(LokiService $loki, TrashNothingRateLimiter $rateLimiter): int
    {
        if (!$this->option('from')) {
            $this->error('--from is required: say how far back to read the feed.');

            return Command::FAILURE;
        }

        $syncer = new UserChangesSyncer(
            !$this->option('commit'),
            false,
            (string) config('freegle.trashnothing.api_key'),
            (string) config('freegle.trashnothing.api_base_url'),
            $loki,
            $rateLimiter,
        );

        $counts = $syncer->backfillTnUserIds(
            Carbon::parse((string) $this->option('from'))->utc()->format('Y-m-d\TH:i:s\Z'),
            Carbon::parse((string) ($this->option('to') ?: 'now'))->utc()->format('Y-m-d\TH:i:s\Z'),
        );

        foreach ($counts as $outcome => $count) {
            $this->line("{$outcome}: {$count}");
        }

        if ($syncer->fetchFailed()) {
            $this->error('A page of the feed could not be read, so this stopped early. Run it again.');

            return Command::FAILURE;
        }

        if (!$this->option('commit')) {
            $this->info('Dry run: nothing written. Add --commit to stamp.');
        }

        return Command::SUCCESS;
    }
}
