<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxIdeasService;
use App\Services\Ux\UxRollupService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Top up the UX ideas backlog: for each funnel (or the one named) with fewer open ideas than
 * freegle.ux.ideas.min_open, ask the support helper for new ones and file them. --force asks
 * whatever the backlog holds; --dry-run prints what would be filed and writes nothing.
 *
 * One funnel failing (the helper down, a bad answer) does not stop the others, but the command
 * exits non-zero so the cron log shows it. A funnel that failed is left alone for a while (2, 4,
 * then 7 days) rather than retried every morning on a run that costs minutes of the shared
 * subscription each time; --force tries it anyway. docs/developers/reference/ux-ideas.md.
 */
#[AsCommand(name: 'ux:ideas-refresh')]
class IdeasRefreshCommand extends Command
{
    protected $signature = 'ux:ideas-refresh
        {--flow= : Only this funnel (default every funnel)}
        {--dry-run : Print the ideas that would be filed and write nothing}
        {--force : Refresh even when the funnel already has enough open ideas or is backing off after a failure}';

    protected $description = 'Ask the support helper for UX ideas for funnels with a thin backlog';

    public function handle(UxIdeasService $service): int
    {
        $flow = $this->option('flow');
        if ($flow !== null && ! in_array($flow, UxRollupService::FLOWS, true)) {
            $this->error('--flow must be one of '.implode(', ', UxRollupService::FLOWS).", got '$flow'.");

            return self::FAILURE;
        }

        $minOpen = (int) config('freegle.ux.ideas.min_open', 3);
        $dryRun = (bool) $this->option('dry-run');
        $failed = false;

        foreach ($flow !== null ? [$flow] : UxRollupService::FLOWS as $f) {
            $open = $service->openCount($f);
            if (! $this->option('force') && $open >= $minOpen) {
                $this->line("$f: $open open ideas, enough (at least $minOpen); skipped.");

                continue;
            }

            $until = $service->backoffUntil($f);
            if (! $this->option('force') && $until !== null && $until->isFuture()) {
                $this->line("$f: backing off after a failure until ".$until->toDateString().'; skipped.');

                continue;
            }

            try {
                $result = $service->refresh($f, $dryRun);
            } catch (\RuntimeException $e) {
                $this->error("$f: ".$e->getMessage());
                $failed = true;
                if (! $dryRun) {
                    $service->recordFailure($f);
                }

                continue;
            }
            if (! $dryRun) {
                $service->recordSuccess($f);
            }

            $this->info(sprintf('%s: %s %d idea(s), %d skipped as already known or empty%s.',
                $f, $dryRun ? 'would file' : 'filed', $result['inserted'], $result['skipped'],
                $result['cost_usd'] !== null ? sprintf(' (cost $%.2f)', $result['cost_usd']) : ''));
            if ($dryRun) {
                foreach ($result['ideas'] as $idea) {
                    $this->line(sprintf('  [%s] (%s) %s', $idea['score'], $idea['type'], $idea['title']));
                    $this->line('      '.$idea['change']);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
