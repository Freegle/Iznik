<?php

namespace App\Console\Commands\Mail;

use App\Services\ReengageService;
use Illuminate\Console\Command;

class SendReengageEmailsCommand extends Command
{
    protected $signature = 'mail:reengage
                            {--dry-run : Report counts without sending emails or recording sends}
                            {--preview= : Send one of each stage with sample data to this address (for mailpit review); bypasses the dark-ship gates}';

    protected $description = 'Send the localised re-engagement sequence to lapsed users (dark unless FREEGLE_REENGAGE_ALLOWLIST + type gate are set)';

    public function handle(ReengageService $service): int
    {
        $preview = $this->option('preview');
        if ($preview) {
            $count = $service->sendPreview((string) $preview);
            $this->info("Sent {$count} preview email(s) to {$preview}");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $result = $service->processReengageEmails($dryRun);

        $this->info("{$prefix}Stage 1 (nearby) sent: {$result['stage1']}");
        $this->info("{$prefix}Stage 2 (impact) sent: {$result['stage2']}");
        $this->info("{$prefix}Stage 3 (preferences) sent: {$result['stage3']}");
        $this->info("{$prefix}Control (holdout, recorded not sent): {$result['control']}");
        $this->info("{$prefix}Sequences suppressed: {$result['suppressed']}");

        return self::SUCCESS;
    }
}
