<?php

namespace App\Console\Commands\Spam;

use App\Services\MobileNetworkService;
use Illuminate\Console\Command;

class RefreshMobileCidrsCommand extends Command
{
    protected $signature = 'spam:refresh-mobile-cidrs';

    protected $description = 'Refresh UK mobile-carrier (RIPEstat) and Cloudflare (ips-v4) IP ranges into spam_whitelist_ips; prunes stale job-owned entries (Discourse #9768)';

    public function handle(MobileNetworkService $service): int
    {
        $count = $service->refresh();
        $this->info("Seeded/updated {$count} CIDR(s) (UK mobile + Cloudflare) into spam_whitelist_ips.");

        return self::SUCCESS;
    }
}
