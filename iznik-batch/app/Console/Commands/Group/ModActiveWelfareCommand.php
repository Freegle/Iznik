<?php

namespace App\Console\Commands\Group;

use App\Services\ModWelfareService;
use Illuminate\Console\Command;

class ModActiveWelfareCommand extends Command
{
    protected $signature = 'groups:check-mod-welfare
                            {--dry-run : Report counts without sending emails or updating records}';

    protected $description = 'Check for inactive mods and notify group owners/mentors (migrated from mod_active.php)';

    public function handle(ModWelfareService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->checkInactiveMods($dryRun);

        $this->line("Inactive mods found: {$result['inactive_mods']}");
        $this->line("Skipped: {$result['skipped']}");

        if ($dryRun) {
            $this->line('[dry-run] No emails sent, no records updated.');
        }

        return Command::SUCCESS;
    }
}
