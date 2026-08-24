<?php

namespace App\Console\Commands\CommunityNews;

use App\Services\CommunityNews\CommunityNewsEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendCommunityNewsEmailCommand extends Command
{
    protected $signature = 'community-news:email
                            {--area= : Only send for this area id}
                            {--force : Ignore the weekly cadence (send even if sent recently)}
                            {--dry-run : Build recipients but do not spool any mail}';

    protected $description = 'Send the weekly Community News digest email for each due area to its deduplicated, opted-in members';

    public function handle(CommunityNewsEmailService $service): int
    {
        // Own mutex, not just the scheduler's withoutOverlapping(): that only
        // guards the cron against itself, so a manual run racing the Friday
        // cron could double-mail an area whose lastemailed wasn't stamped yet.
        // This lock covers both invocation paths. Dry runs don't take it.
        $dryRun = (bool) $this->option('dry-run');
        $area = $this->option('area') !== null ? (int) $this->option('area') : null;

        $lock = $dryRun ? null : Cache::lock('community-news:email', 7200);
        if ($lock && !$lock->get()) {
            $this->warn('Another community-news:email run is in progress; skipping.');

            return self::SUCCESS;
        }

        try {
            $result = $service->sendWeekly($dryRun, $area, (bool) $this->option('force'));
        } finally {
            $lock?->release();
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Spooled {$result['sent']} mail(s) across {$result['areas']} area(s).");

        return self::SUCCESS;
    }
}
