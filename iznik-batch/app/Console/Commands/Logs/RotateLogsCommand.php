<?php

namespace App\Console\Commands\Logs;

use App\Services\LogRotationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'logs:rotate')]
class RotateLogsCommand extends Command
{
    protected $signature = 'logs:rotate
                            {--days=7 : Days of log files to retain before deletion}
                            {--dry-run : Report what would change without modifying any files}';

    protected $description = 'Archive cron logs, compress rotated batch log files and prune those older than the retention window';

    public function handle(LogRotationService $service): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $dir = storage_path('logs');

        if ($dryRun) {
            $this->info('Dry run: no files will be changed.');
        }

        // Prune first so we never spend time compressing a file that is about to be deleted.
        $pruned = $service->prune($dir, $days, $dryRun);
        // Per-command cron logs are appended to, so archive and empty them daily.
        $rotated = $service->rotateLive($dir.'/cron', $dryRun);
        $compressed = $service->compress($dir, $dryRun);

        $this->table(
            ['Action', 'Files', 'Bytes'],
            [
                ['Pruned (older than '.$days.' days)', $pruned['deleted'], number_format($pruned['bytes'])],
                ['Cron logs archived and emptied', $rotated['rotated'], number_format($rotated['bytes'])],
                ['Compressed', $compressed['compressed'], number_format($compressed['bytes_before'])],
            ]
        );

        $this->info(sprintf(
            'logs:rotate complete - pruned %d file(s) (%s bytes), archived %d cron log(s), compressed %d file(s).',
            $pruned['deleted'],
            number_format($pruned['bytes']),
            $rotated['rotated'],
            $compressed['compressed']
        ));

        return self::SUCCESS;
    }
}
