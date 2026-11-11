<?php

namespace App\Console\Commands\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use App\Services\CommunityNews\CommunityNewsLimitReached;
use App\Services\CommunityNews\CommunityNewsResearchService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'community-news:research')]
class ResearchCommunityNewsCommand extends Command
{
    protected $signature = 'community-news:research
                            {--area= : Only research this area id}
                            {--min-days= : Re-research an area only if last research is older than this many days}
                            {--force : Research even if researched recently}
                            {--max= : Research at most this many areas in this run (default: research_max_per_run; 0 for no limit)}
                            {--dry-run : Call the model but do not store items}';

    protected $description = 'Cluster communitynews-enabled communities into areas and research local news for each';

    public function handle(CommunityNewsAreaService $areas, CommunityNewsResearchService $research): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minDays = $this->option('min-days') !== null
            ? (int) $this->option('min-days')
            : (int) config('freegle.communitynews.chitchat_min_days', 3);

        // Same manual-vs-cron mutex as community-news:email: a long manual run
        // (e.g. after a mass enablement, ~minutes of model time per new area)
        // must not have the hourly cron start a second copy and double-research
        // areas whose lastresearched isn't stamped yet. Generous TTL — a mass
        // run can span hours; released in finally on any exit.
        $lock = $dryRun ? null : \Illuminate\Support\Facades\Cache::lock('community-news:research', 4 * 3600);
        if ($lock && !$lock->get()) {
            $this->warn('Another community-news:research run is in progress; skipping.');

            return self::SUCCESS;
        }

        try {
            return $this->research($areas, $research, $dryRun, $minDays);
        } finally {
            $lock?->release();
        }
    }

    private function research(CommunityNewsAreaService $areas, CommunityNewsResearchService $research, bool $dryRun, int $minDays): int
    {
        $built = $areas->rebuildAreas();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Areas: {$built->count()} from communitynews-enabled communities.");

        // Oldest first, and only a few areas per hourly run, so a whole cadence of research is spread over the
        // cadence instead of all landing in the first run after the areas fall due or the subscription resets.
        // The subscription is shared with the AI support helper, and one burst used up its week.
        $query = CommunityNewsArea::query()->orderByRaw('lastresearched IS NOT NULL, lastresearched')->orderBy('id');
        $max = $this->option('max') !== null
            ? (int) $this->option('max')
            : (int) config('freegle.communitynews.research_max_per_run', 7);
        if ($this->option('area')) {
            $query->where('id', (int) $this->option('area'));
            $max = 0;
        }
        $attempted = 0;

        $researched = 0;
        $itemsTotal = 0;

        foreach ($query->get() as $area) {
            if (!$this->option('force')
                && $area->lastresearched
                && $area->lastresearched->gt(now()->subDays($minDays))) {
                $this->line("  skip #{$area->id} {$area->name} (researched {$area->lastresearched->diffForHumans()})");
                continue;
            }

            if ($max > 0 && $attempted >= $max) {
                $this->line("  stopping: {$max} area(s) this run");
                break;
            }
            $attempted++;

            try {
                $result = $research->researchArea($area, $dryRun);
            } catch (CommunityNewsLimitReached $e) {
                $this->warn("  stopping: the model refused, {$e->getMessage()}");
                break;
            } catch (\Throwable $e) {
                $this->error("  {$area->name}: {$e->getMessage()}");
                continue;
            }

            if ($result['ok']) {
                $researched++;
                $itemsTotal += $result['items'];
                $this->info("  #{$area->id} {$area->name}: {$result['items']} item(s)" . ($dryRun ? ' (not stored)' : ''));
            } else {
                $this->warn("  #{$area->id} {$area->name}: no items found");
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Researched {$researched} area(s), {$itemsTotal} item(s) total.");

        return self::SUCCESS;
    }
}
