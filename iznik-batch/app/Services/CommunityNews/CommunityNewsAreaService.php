<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups the communitynews-enabled Freegle groups into "areas" anchored on the
 * `towns` table.
 *
 * The research call searches around the area's NAME, and local news supply is
 * organised by named place (a paper's patch, a council's what's-on) — so the
 * area unit must be a real, searchable town, not a distance blob. Each enabled
 * group joins its nearest town within `area_cluster_miles`; the town's name and
 * centre become the area's. Distance clustering (union-find) was tried first
 * but chains transitively: with every group enabled, mainland England collapses
 * into one 400-group component spanning 300+ miles. Town anchoring can't chain,
 * so it still works when all groups are active (~240 areas). A group with no
 * town within the cap — and every group, when the towns table is empty (dev) —
 * stands alone as its own area, named from the group.
 *
 * Areas are keyed by `anchorgroupid` (the lowest enabled groupid on the town)
 * so a re-run upserts the same row and keeps its cadence timers.
 */
class CommunityNewsAreaService
{
    /** Earth radius in miles (mean). */
    private const EARTH_MILES = 3958.7559;

    /**
     * Recompute areas from the current enabled-group set and upsert them.
     * Stale areas (whose anchor no longer holds one) are removed, cascading to
     * their items.
     *
     * @return Collection<int, CommunityNewsArea>
     */
    public function rebuildAreas(): Collection
    {
        $capMiles = (float) config('freegle.communitynews.area_cluster_miles', 20);

        $groups = Group::activeFreegle()
            ->communityNewsEnabled()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'nameshort', 'namefull', 'lat', 'lng'])
            ->filter(function ($g) {
                return $g->lat !== null && $g->lng !== null && !((float) $g->lat === 0.0 && (float) $g->lng === 0.0);
            })
            ->values();

        $towns = DB::table('towns')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng']);

        // Assign each group to its nearest town within the cap; the rest stand
        // alone. O(groups × towns) haversines — trivial at this scale.
        $byTown = [];
        $standalone = [];
        foreach ($groups as $g) {
            $best = null;
            $bestDist = INF;
            foreach ($towns as $t) {
                $d = $this->haversineMiles((float) $g->lat, (float) $g->lng, (float) $t->lat, (float) $t->lng);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $t;
                }
            }
            if ($best !== null && $bestDist <= $capMiles) {
                $byTown[$best->id]['town'] = $best;
                $byTown[$best->id]['groups'][] = $g;
            } else {
                $standalone[] = $g;
            }
        }

        $areas = collect();
        $seenAnchors = [];

        foreach ($byTown as $bucket) {
            $groupIds = collect($bucket['groups'])->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $anchor = $groupIds[0];
            $seenAnchors[] = $anchor;

            $areas->push(CommunityNewsArea::updateOrCreate(
                ['anchorgroupid' => $anchor],
                [
                    'name' => $bucket['town']->name,
                    'lat' => round((float) $bucket['town']->lat, 6),
                    'lng' => round((float) $bucket['town']->lng, 6),
                    'groupids' => $groupIds,
                    'groupcount' => count($groupIds),
                ]
            ));
        }

        foreach ($standalone as $g) {
            $anchor = (int) $g->id;
            $seenAnchors[] = $anchor;

            $areas->push(CommunityNewsArea::updateOrCreate(
                ['anchorgroupid' => $anchor],
                [
                    'name' => $this->areaName(collect([$g])),
                    'lat' => round((float) $g->lat, 6),
                    'lng' => round((float) $g->lng, 6),
                    'groupids' => [$anchor],
                    'groupcount' => 1,
                ]
            ));
        }

        // Re-home history from areas whose shape changed: when an old area's
        // anchor group now lives inside a different area (a mass enablement or
        // a towns-table change can re-anchor neighbours under a new, lower
        // anchor id), move its items across and carry its cadence stamps
        // forward. Without this, the FK cascade silently destroys
        // posted/emailed bookkeeping and the engagement linkage, and the reset
        // cadences re-mail members early. An area whose groups left the
        // feature entirely still deletes (with its items) — that removal is
        // genuine.
        $stale = CommunityNewsArea::query();
        if (!empty($seenAnchors)) {
            $stale->whereNotIn('anchorgroupid', $seenAnchors);
        }

        foreach ($stale->get() as $old) {
            $new = $areas->first(fn ($a) => in_array((int) $old->anchorgroupid, array_map('intval', $a->groupids ?? []), true));

            if ($new) {
                CommunityNewsItem::where('areaid', $old->id)->update(['areaid' => $new->id]);

                foreach (['lastresearched', 'lastposted', 'lastemailed'] as $stamp) {
                    $new->{$stamp} = collect([$new->{$stamp}, $old->{$stamp}])->filter()->max();
                }
                $new->save();
            }

            $old->delete();
        }

        return $areas;
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
