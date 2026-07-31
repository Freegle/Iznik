<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * Clusters the communitynews-enabled Freegle groups into "areas".
 *
 * A small town (Edinburgh, Oxford) sits on its own; a dense cluster of boroughs
 * merges into one area so news is researched once and members are deduplicated
 * across it. Clustering is greedy union-find on great-circle centre distance —
 * neighbouring enabled groups within `area_cluster_miles` join the same area.
 * This approximates the ~30-min Rippling-Out reach without a routing call; a
 * drive-time refinement can replace clusterByDistance() later.
 *
 * Areas are keyed by `anchorgroupid` (the lowest enabled groupid in the cluster)
 * so a re-run upserts the same row and keeps its cadence timers.
 */
class CommunityNewsAreaService
{
    /** Earth radius in miles (mean). */
    private const EARTH_MILES = 3958.7559;

    /**
     * Recompute areas from the current enabled-group set and upsert them.
     * Stale areas (whose anchor no longer clusters) are removed, cascading to
     * their items.
     *
     * @return Collection<int, CommunityNewsArea>
     */
    public function rebuildAreas(): Collection
    {
        $miles = (float) config('freegle.communitynews.area_cluster_miles', 20);

        $groups = Group::activeFreegle()
            ->communityNewsEnabled()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'nameshort', 'namefull', 'lat', 'lng'])
            ->filter(function ($g) {
                return $g->lat !== null && $g->lng !== null && !((float) $g->lat === 0.0 && (float) $g->lng === 0.0);
            })
            ->values();

        $clusters = $this->clusterByDistance($groups, $miles);

        $areas = collect();
        $seenAnchors = [];

        foreach ($clusters as $members) {
            $groupIds = $members->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $anchor = $groupIds[0];
            $seenAnchors[] = $anchor;

            $area = CommunityNewsArea::updateOrCreate(
                ['anchorgroupid' => $anchor],
                [
                    'name' => $this->areaName($members),
                    'lat' => round((float) $members->avg('lat'), 6),
                    'lng' => round((float) $members->avg('lng'), 6),
                    'groupids' => $groupIds,
                    'groupcount' => count($groupIds),
                ]
            );
            $areas->push($area);
        }

        // Drop areas that no longer correspond to a live cluster (items cascade).
        $stale = CommunityNewsArea::query();
        if (!empty($seenAnchors)) {
            $stale->whereNotIn('anchorgroupid', $seenAnchors);
        }
        $stale->delete();

        return $areas;
    }

    /**
     * Greedy union-find: connect any two groups whose centres are within $miles;
     * connected components become areas.
     *
     * @param  Collection $groups  group rows with ->id, ->lat, ->lng, ->namefull
     * @return Collection<int, Collection>  one collection of group rows per area
     */
    public function clusterByDistance(Collection $groups, float $miles): Collection
    {
        $list = $groups->values();
        $n = $list->count();

        $parent = range(0, max(0, $n - 1));
        $find = function (int $x) use (&$parent) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = $this->haversineMiles(
                    (float) $list[$i]->lat,
                    (float) $list[$i]->lng,
                    (float) $list[$j]->lat,
                    (float) $list[$j]->lng
                );
                if ($d <= $miles) {
                    $ri = $find($i);
                    $rj = $find($j);
                    if ($ri !== $rj) {
                        $parent[$ri] = $rj;
                    }
                }
            }
        }

        $components = [];
        for ($i = 0; $i < $n; $i++) {
            $components[$find($i)][] = $list[$i];
        }

        return collect($components)->map(fn ($rows) => collect($rows))->values();
    }

    public function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_MILES * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Best-effort human label for an area from its member group names. Freegle
     * short names look like "EdinburghFreegle" / "HackneyFreegle"; strip the
     * "Freegle" suffix and, for multi-group areas, tag "& nearby".
     */
    public function areaName(Collection $members): string
    {
        $primary = $members->sortBy('id')->first();
        $raw = $primary->namefull ?: ($primary->nameshort ?: 'your area');

        $clean = trim(preg_replace('/\s{2,}/', ' ', preg_replace('/\bfreegle\b/i', '', $raw)));
        if ($clean === '') {
            $clean = $raw;
        }

        return $members->count() > 1 ? $clean . ' & nearby' : $clean;
    }
}
