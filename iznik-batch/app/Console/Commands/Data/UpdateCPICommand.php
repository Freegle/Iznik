<?php

namespace App\Console\Commands\Data;

use App\Services\CPIService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to fetch and store UK CPI data from ONS.
 *
 * This should be scheduled to run monthly to keep inflation data up to date.
 * The CPI data is used to inflation-adjust the "benefit of reuse" value
 * (£711 per tonne from 2011) to current year prices.
 */
class UpdateCPICommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'data:update-cpi';

    /**
     * The console command description.
     */
    protected $description = 'Fetch UK CPI (Consumer Price Index) data from ONS and store in config table';

    /**
     * Execute the console command.
     */
    public function handle(CPIService $cpiService): int
    {
        $this->registerShutdownHandlers();

        Log::info('Starting CPI data update');
        $this->info('Fetching CPI data from ONS...');

        $result = $cpiService->fetchAndStoreCPI();

        if ($result['success']) {
            $latestYear = max(array_keys($result['data']));
            $latestValue = $result['data'][$latestYear];

            $this->info('CPI data updated successfully.');
            $this->info("Latest year: {$latestYear}, CPI value: {$latestValue}");
            $this->info('Years available: ' . count($result['data']));

            Log::info('CPI data update complete', [
                'latest_year' => $latestYear,
                'latest_value' => $latestValue,
            ]);

            return Command::SUCCESS;
        } else {
            $this->error('Failed to update CPI data: ' . $result['message']);
            $this->warn('The system will continue using previously stored data or fallback values.');

            Log::error('CPI data update failed', ['error' => $result['message']]);

            return Command::FAILURE;
        }
    }
}
