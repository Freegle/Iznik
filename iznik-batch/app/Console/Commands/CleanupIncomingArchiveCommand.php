<?php

namespace App\Console\Commands;

use App\Services\Mail\Incoming\IncomingArchiveService;
use Illuminate\Console\Command;

class CleanupIncomingArchiveCommand extends Command
{
    protected $signature = 'mail:cleanup-archive {--hours=48 : Maximum age in hours}';

    protected $description = 'Delete incoming email archive files older than the specified age';

    public function handle(IncomingArchiveService $archiveService): int
    {
        $hours = (int) $this->option('hours');
        $deleted = $archiveService->cleanup($hours);

        $this->info("Deleted {$deleted} archive file(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
