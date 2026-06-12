<?php

namespace App\Console\Commands\Discourse;

use App\Services\DiscourseCheckUsersService;
use Illuminate\Console\Command;

/**
 * Daily Discourse user audit (V1 cron/discourse_checkusers.php): verifies mods,
 * keeps bios/announcements watching in sync, reports bounces and mail settings.
 */
class CheckUsersCommand extends Command
{
    protected $signature = 'discourse:check-users';

    protected $description = 'Audit Discourse users against MT mods; fix watching/bios; email a report (V1 discourse_checkusers.php)';

    public function handle(DiscourseCheckUsersService $service): int
    {
        $result = $service->run();

        if ($result['skipped'] ?? false) {
            $this->warn('Discourse API key not configured — skipped.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Done. mods=%d, notmod=%d, notuser=%d, announcements-added=%d, bios-updated=%d.',
            $result['ismod'], $result['notmod'], $result['notuser'],
            $result['notonannouncements'], $result['bioupdatedcount']
        ));

        return self::SUCCESS;
    }
}
