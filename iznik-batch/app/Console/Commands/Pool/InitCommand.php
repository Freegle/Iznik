<?php

namespace App\Console\Commands\Pool;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Console\Command;

class InitCommand extends Command
{
    protected $signature = 'pool:init
                            {name? : Specific pool to initialize (default: all)}
                            {--reset : Reset pool permits (clears all existing permits)}';

    protected $description = 'Initialize worker pool permits in Redis';

    /**
     * Pool configurations with their config keys.
     */
    protected array $pools = [
        'mjml' => 'pools.mjml.max',
        'digest' => 'pools.digest.max',
    ];

    public function handle(): int
    {
        $specificPool = $this->argument('name');
        $reset = $this->option('reset');

        if ($specificPool) {
            return $this->initPool($specificPool, $reset);
        }

        return $this->initAllPools($reset);
    }

    protected function initAllPools(bool $reset): int
    {
        $this->info($reset ? 'Resetting all worker pools...' : 'Initializing worker pools...');
        $this->newLine();

        foreach ($this->pools as $name => $configKey) {
            $this->initPool($name, $reset, false);
        }

        $this->newLine();
        $this->info('Done!');

        return Command::SUCCESS;
    }

    protected function initPool(string $name, bool $reset, bool $showDone = true): int
    {
        $configKey = $this->pools[$name] ?? null;
        $maxConcurrency = $configKey ? config($configKey, 10) : 10;

        $pool = new BoundedPool(
            name: $name,
            maxConcurrency: $maxConcurrency
        );

        if ($reset) {
            $pool->reset();
            $this->line("  <info>✓</info> {$name}: Reset with {$maxConcurrency} permits");
        } else {
            $pool->initialize();
            $this->line("  <info>✓</info> {$name}: Initialized to {$maxConcurrency} permits");
        }

        if ($showDone) {
            $this->newLine();
            $this->info('Done!');
        }

        return Command::SUCCESS;
    }
}
