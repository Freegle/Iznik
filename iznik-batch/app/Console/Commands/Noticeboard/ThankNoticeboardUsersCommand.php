<?php

namespace App\Console\Commands\Noticeboard;

use App\Services\NoticeboardService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'noticeboards:thank-users')]
class ThankNoticeboardUsersCommand extends Command
{
    protected $signature = 'noticeboards:thank-users
                            {--dry-run : Preview without sending}';

    protected $description = 'Send thank-you emails to users who added noticeboards';

    public function handle(NoticeboardService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $count = $service->thankUsers($dryRun);

        $prefix = $dryRun ? '[DRY RUN] Would thank' : 'Thanked';
        $this->info("{$prefix} {$count} user(s).");

        return self::SUCCESS;
    }
}
