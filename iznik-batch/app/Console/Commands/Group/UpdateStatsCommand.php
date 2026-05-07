<?php

namespace App\Console\Commands\Group;

use App\Services\GroupStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateStatsCommand extends Command
{
    protected $signature = 'groups:update-stats';

    protected $description = 'Update group stats: repost settings, polyindex, activity/funding, moderation counts, and stats_outcomes';

    public function handle(GroupStatsService $service): int
    {
        Log::info('Starting group stats update');

        $result = $service->updateGroupStats();

        $this->info("Group stats: {$result['reposts_fixed']} reposts fixed, {$result['polyindex_fixed']} polyindices fixed, {$result['stats_outcomes_updated']} outcomes updated.");
        Log::info('Group stats update complete', $result);

        return Command::SUCCESS;
    }
}
