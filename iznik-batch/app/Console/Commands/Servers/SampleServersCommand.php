<?php

namespace App\Console\Commands\Servers;

use App\Services\Servers\ServerSampler;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'servers:sample')]
class SampleServersCommand extends Command
{
    protected $signature = 'servers:sample';

    protected $description = 'Record load, processor time, memory and disk for each monitored host';

    // The sampler takes the ssh runner, so it is built here and not passed to handle().
    public function __construct(private readonly ServerSampler $sampler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->sampler->targets() === []) {
            $this->info('No hosts configured (FREEGLE_MONITORING_HOSTS is empty); nothing to sample.');

            return self::SUCCESS;
        }

        $result = $this->sampler->sampleAll();
        $pruned = $this->sampler->prune();

        $this->info(sprintf(
            'Sampled %d host(s): %s. %d did not answer%s. Removed %d old reading(s).',
            count($result['sampled']),
            implode(', ', $result['sampled']) ?: 'none',
            count($result['failed']),
            $result['failed'] ? ' ('.implode(', ', $result['failed']).')' : '',
            $pruned,
        ));

        // One host missing is worth a log line, not a failed job; every host missing is a fault.
        return $result['sampled'] === [] ? self::FAILURE : self::SUCCESS;
    }
}
