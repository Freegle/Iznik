<?php

namespace App\Console\Commands\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use App\Services\CommunityNews\CommunityNewsResearchService;
use Illuminate\Console\Command;

class ResearchCommunityNewsCommand extends Command
{
    protected $signature = 'community-news:research
                            {--area= : Only research this area id}
                            {--min-days= : Re-research an area only if last research is older than this many days}
                            {--force : Research even if researched recently}
                            {--dry-run : Call the model but do not store items}';

    protected $description = 'Cluster communitynews-enabled communities into areas and research local news for each';

    public function handle(CommunityNewsAreaService $areas, CommunityNewsResearchService $research): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minDays = $this->option('min-days') !== null
            ? (int) $this->option('min-days')
            : (int) config('freegle.communitynews.chitchat_min_days', 3);

        $built = $areas->rebuildAreas();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Areas: {$built->count()} from communitynews-enabled communities.");

        $query = CommunityNewsArea::query();
        if ($this->option('area')) {
            $query->where('id', (int) $this->option('area'));
        }

        $researched = 0;
        $itemsTotal = 0;

        foreach ($query->get() as $area) {
            if (!$this->option('force')
                && $area->lastresearched
                && $area->lastresearched->gt(now()->subDays($minDays))) {
                $this->line("  skip #{$area->id} {$area->name} (researched {$area->lastresearched->diffForHumans()})");
                continue;
            }

            try {
                $result = $research->researchArea($area, $dryRun);
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
