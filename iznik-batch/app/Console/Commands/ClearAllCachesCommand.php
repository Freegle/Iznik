<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @deprecated Use deploy:refresh instead. This alias exists for backward compatibility.
 */
#[AsCommand(name: 'clear:all')]
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
