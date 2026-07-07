<?php

namespace App\Console\Commands\Purge;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeAllCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'purge:all
                            {--dry-run : Show what would be purged without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Run all purge operations';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting complete purge', ['dry_run' => $dryRun]);
        $this->info('Running all purge operations...');
        $this->newLine();

        $results = $purgeService->runAll($dryRun);

        $this->info('All purge operations complete.');
        $this->newLine();

        $this->table(
            ['Operation', 'Records Purged'],
            collect($results)->map(fn ($count, $key) => [
                str_replace('_', ' ', ucwords($key)),
                number_format($count),
            ])->toArray()
        );

        $total = array_sum($results);
        $this->newLine();
        $this->info("Total records purged: " . number_format($total));

        Log::info('Complete purge finished', ['total' => $total, 'breakdown' => $results]);

        return Command::SUCCESS;
    }
}
