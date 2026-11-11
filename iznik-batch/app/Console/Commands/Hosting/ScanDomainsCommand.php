<?php

namespace App\Console\Commands\Hosting;

use App\Services\Hosting\DomainHostingService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Looks at the web domains in active members' email addresses for a WordPress site and whether it is
 * on Krystal, and writes domain_hosting. The Krystal hosting ad reads that table.
 * docs/developers/reference/ux-experiments.md, "A hosting ad for members with a WordPress site".
 */
#[AsCommand(name: 'hosting:scan-domains')]
class ScanDomainsCommand extends Command
{
    protected $signature = 'hosting:scan-domains {--limit=300 : How many domains to look at in this run}';

    protected $description = 'Check active members\' email domains for a WordPress site and its host, for the hosting ad';

    public function handle(DomainHostingService $service): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $counts = $service->scan($limit);

        $this->info(sprintf(
            'Looked at %d domains: %d WordPress, %d on Krystal, %d unreachable.',
            $counts['looked'], $counts['wordpress'], $counts['krystal'], $counts['unreachable']
        ));

        return self::SUCCESS;
    }
}
