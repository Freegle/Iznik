<?php

namespace App\Console\Commands\Group;

use App\Services\GroupStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateStatsCommand extends Command
{
    protected $signature = 'groups:update-stats
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update group stats: repost settings, polyindex, activity/funding, moderation counts, and stats_outcomes';

    public function handle(GroupStatsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — scanning groups but not writing.');
        } else {
            Log::info('Starting group stats update');
        }

        $result = $service->updateGroupStats($dryRun);

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb}:");
        $this->line(sprintf('  reposts_fixed:            %d', $result['reposts_fixed']));
        $this->line(sprintf('  polyindex_fixed:          %d', $result['polyindex_fixed']));
        $this->line(sprintf('  activity_updated:         %d (groups)', $result['activity_updated']));
        $this->line(sprintf('  last_moderated_updated:   %d (groups)', $result['last_moderated_updated']));
        $this->line(sprintf('  last_autoapprove_updated: %d (groups, only those with logs)', $result['last_autoapprove_updated']));
        $this->line(sprintf('  mod_counts_updated:       %d (groups)', $result['mod_counts_updated']));
        $this->line(sprintf('  stats_outcomes_updated:   %d', $result['stats_outcomes_updated']));

        if (!$dryRun) {
            Log::info('Group stats update complete', $result);
        }

        return Command::SUCCESS;
    }
}
