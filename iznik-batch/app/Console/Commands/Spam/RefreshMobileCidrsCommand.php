<?php

namespace App\Console\Commands\Spam;

use App\Services\MobileNetworkService;
use Illuminate\Console\Command;

class RefreshMobileCidrsCommand extends Command
{
    protected $signature = 'spam:refresh-mobile-cidrs';

    protected $description = 'Refresh UK mobile-carrier IP ranges (from RIPEstat) into spam_whitelist_ips so CGNAT shared-egress IPs are exempt from the IP-abuse check (Discourse #9768)';

    public function handle(MobileNetworkService $service): int
    {
        $count = $service->refresh();
        $this->info("Seeded/updated {$count} UK mobile CIDR(s) into spam_whitelist_ips.");

        return self::SUCCESS;
    }
}
