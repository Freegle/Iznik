<?php

namespace App\Console\Commands\Mail;

use App\Services\ReengageService;
use Illuminate\Console\Command;

class UpdateReengageOutcomesCommand extends Command
{
    protected $signature = 'mail:reengage-outcomes
                            {--window= : Override the outcome window in days (default from config)}';

    protected $description = 'Record real re-engagement outcomes (login/reply/post within the window) for reengage sends, so effectiveness and control-arm lift can be graphed';

    public function handle(ReengageService $service): int
    {
        $window = $this->option('window');
        $result = $service->updateOutcomes($window !== null ? (int) $window : null);

        $this->info("Checked: {$result['checked']}");
        $this->info("Newly re-engaged: {$result['reengaged']}");

        return self::SUCCESS;
    }
}
