<?php

namespace App\Console\Commands\Pool;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class StatusCommand extends Command
{
    protected $signature = 'pool:status {name? : Specific pool name to show}';

    protected $description = 'Show worker pool status and statistics';

    /**
     * Default pool configurations to check.
     */
    protected array $defaultPools = [
        'mjml' => 'pools.mjml.max',
        'digest' => 'pools.digest.max',
    ];

    public function handle(): int
    {
        $specificPool = $this->argument('name');

        if ($specificPool) {
            return $this->showPoolStatus($specificPool);
        }

        return $this->showAllPools();
    }

    protected function showAllPools(): int
    {
        $this->info('Worker Pool Status');
        $this->newLine();

        $rows = [];

        foreach ($this->defaultPools as $name => $configKey) {
            $maxConcurrency = config($configKey, 10);
            $stats = $this->getPoolStats($name, $maxConcurrency);

            $rows[] = [
                $stats['name'],
                $stats['max_concurrency'],
                $stats['available'],
                $stats['in_use'],
                $this->formatUtilization($stats),
                $stats['total_acquired'],
                $stats['timeouts'],
            ];
        }

        $this->table(
            ['Pool', 'Max', 'Available', 'In Use', 'Utilization', 'Total Acquired', 'Timeouts'],
            $rows
        );

        return Command::SUCCESS;
    }

    protected function showPoolStatus(string $name): int
    {
        $configKey = $this->defaultPools[$name] ?? null;
        $maxConcurrency = $configKey ? config($configKey, 10) : 10;

        $stats = $this->getPoolStats($name, $maxConcurrency);

        $this->info("Pool: {$stats['name']}");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Max Concurrency', $stats['max_concurrency']],
                ['Available Permits', $stats['available']],
                ['In Use', $stats['in_use']],
                ['Utilization', $this->formatUtilization($stats)],
                ['Total Acquired', $stats['total_acquired']],
                ['Timeouts', $stats['timeouts']],
            ]
        );

        // Show capacity warning
        if ($stats['available'] === 0) {
            $this->newLine();
            $this->warn('⚠️  Pool is at maximum capacity! Requests are blocking.');
        } elseif ($stats['in_use'] > $stats['max_concurrency'] * 0.8) {
            $this->newLine();
            $this->warn('⚠️  Pool is approaching capacity (>80% utilization).');
        }

        return Command::SUCCESS;
    }

    protected function getPoolStats(string $name, int $maxConcurrency): array
    {
        $permitsKey = "pool:{$name}:permits";
        $statsKey = "pool:{$name}:stats";

        $available = Redis::llen($permitsKey);

        return [
            'name' => $name,
            'max_concurrency' => $maxConcurrency,
            'available' => $available,
            'in_use' => max(0, $maxConcurrency - $available),
            'timeouts' => (int) (Redis::hget($statsKey, 'timeouts') ?? 0),
            'total_acquired' => (int) (Redis::hget($statsKey, 'acquired') ?? 0),
        ];
    }

    protected function formatUtilization(array $stats): string
    {
        if ($stats['max_concurrency'] === 0) {
            return 'N/A';
        }

        $percentage = ($stats['in_use'] / $stats['max_concurrency']) * 100;

        return sprintf('%.1f%%', $percentage);
    }
}
