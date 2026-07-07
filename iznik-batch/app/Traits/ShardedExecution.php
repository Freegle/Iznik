<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait ShardedExecution
{
    protected int $mod = 1;
    protected int $val = 0;

    /**
     * Apply modulo-based sharding to a query.
     *
     * This allows distributing work across multiple processes.
     * For example, with --mod=4 --val=0, this process handles items where id % 4 = 0.
     */
    protected function applySharding(Builder $query, string $column = 'id'): Builder
    {
        if ($this->mod > 1) {
            return $query->whereRaw("MOD({$column}, ?) = ?", [$this->mod, $this->val]);
        }

        return $query;
    }

    /**
     * Parse sharding options from command input.
     */
    protected function parseShardingOptions(): void
    {
        if (method_exists($this, 'option')) {
            $this->mod = (int) ($this->option('mod') ?? 1);
            $this->val = (int) ($this->option('val') ?? 0);
        }
    }

    /**
     * Get common sharding option definitions for commands.
     */
    protected function getShardingOptions(): array
    {
        return [
            ['mod', 'm', \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Modulo for sharding (default: 1, no sharding)', 1],
            ['val', 'v', \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Value for sharding (default: 0)', 0],
        ];
    }

    /**
     * Log sharding configuration.
     */
    protected function logShardingConfig(): void
    {
        if ($this->mod > 1 && method_exists($this, 'info')) {
            $this->info("Sharding enabled: processing items where id MOD {$this->mod} = {$this->val}");
        }
    }
}
