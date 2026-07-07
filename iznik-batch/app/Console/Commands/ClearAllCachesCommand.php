<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * @deprecated Use deploy:refresh instead. This alias exists for backward compatibility.
 */
class ClearAllCachesCommand extends Command
{
    protected $signature = 'clear:all';

    protected $description = 'Alias for deploy:refresh (deprecated - use deploy:refresh instead)';

    public function handle(): int
    {
        $this->warn('clear:all is deprecated. Use deploy:refresh instead.');
        $this->newLine();

        return $this->call('deploy:refresh');
    }
}
