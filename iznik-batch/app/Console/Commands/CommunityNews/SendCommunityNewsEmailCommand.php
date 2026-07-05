<?php

namespace App\Console\Commands\CommunityNews;

use App\Services\CommunityNews\CommunityNewsEmailService;
use Illuminate\Console\Command;

class SendCommunityNewsEmailCommand extends Command
{
    protected $signature = 'community-news:email
                            {--area= : Only send for this area id}
                            {--force : Ignore the weekly cadence (send even if sent recently)}
                            {--dry-run : Build recipients but do not spool any mail}';

    protected $description = 'Send the weekly Community News digest email for each due area to its deduplicated, opted-in members';

    public function handle(CommunityNewsEmailService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $area = $this->option('area') !== null ? (int) $this->option('area') : null;

        $result = $service->sendWeekly($dryRun, $area, (bool) $this->option('force'));

        $this->info(($dryRun ? '[dry-run] ' : '') . "Spooled {$result['sent']} mail(s) across {$result['areas']} area(s).");

        return self::SUCCESS;
    }
}
