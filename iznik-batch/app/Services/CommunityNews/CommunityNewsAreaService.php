<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Groups the communitynews-enabled Freegle groups into "areas", each anchored on
 * a town.
 *
 * The research call searches around the area's NAME, and local news supply is
 * organised by named place (a paper's patch, a council's what's-on) — so the
 * area unit must be a real, searchable town, not a distance blob. Each enabled
 * group is anchored on the town closest to it (anchorFor), chosen from the
 * curated `towns` and the `places` gazetteer; groups that share an anchor form
 * one area, and the town's name and centre become the area's. Distance
 * clustering (union-find) was tried first but chains transitively: with every
 * group enabled, mainland England collapses into one 400-group component
 * spanning 300+ miles. Anchoring on a town can't chain. A group with no town
 * within `area_cluster_miles` — and every group, when both lists are empty
 * (dev) — stands alone as its own area, named from the group. A town in a
 * different nation from the group is never eligible.
 *
 * Areas are keyed by `anchorgroupid` (the lowest enabled groupid on the town)
 * so a re-run upserts the same row and keeps its cadence timers.
 */
class CommunityNewsAreaService
{
    /** Earth radius in miles (mean). */
    private const EARTH_MILES = 3958.7559;

    /**
     * The places an area actually covers, biggest first.
     *
     * The area's NAME is only ever one town, and often not even one inside it:
     * measured 2026-09-05, 298 of the 496 live groups with a polygon (60%)
     * contain no curated town, so they anchor to whatever is nearest outside -
     * Oswestry Freegle's area is named "Wrecsam", 12.7 miles away and in
     * another country. Research written against that name alone is research
     * about the wrong place, and for a wide group (Fife runs Dunfermline to
     * St Andrews) it is about one corner of it.
     *
     * So ask the gazetteer instead: every place whose point falls inside one of
     * the area's groups, biggest first. Median 6 per area, p90 14, hence the
     * cap - a prompt listing forty villages buys nothing and crowds out the
     * instructions.
     *
     * Anchoring and naming deliberately still come from `towns`. Nothing here
     * changes which areas exist, so no area is created, destroyed or re-stamped
     * by this, and the ChitChat and email cadences carry on untouched.
     *
     * @return array<int, string>
     */
    public function placesCovered(CommunityNewsArea $area, ?int $cap = null): array
    {
        $cap = $cap ?? (int) config('freegle.communitynews.places_per_area', 8);
        $ids = array_map('intval', $area->groupids ?? []);
        if (empty($ids) || $cap < 1) {
            return [];
        }

        // The research job runs hourly, so it can land between this code
        // deploying and the migration running. Degrade to the area name rather
        // than fataling - but say so, because a guard like this that stays
        // quiet is how a missing table survives for months.
        if (!Schema::hasTable('places')) {
            Log::warning('CommunityNews: no places table, so areas cover only their anchor town');

            return [];
        }

        $names = DB::table('places')
            ->crossJoin('groups')
            ->whereIn('groups.id', $ids)
            ->whereNotNull('groups.polyindex')
            ->whereRaw('ST_Contains(groups.polyindex, places.position)')
            ->groupBy('places.id', 'places.name', 'places.population')
            ->orderByDesc('places.population')
            ->limit($cap)
            ->pluck('places.name')
            ->all();

        // The area is named after somewhere for a reason; keep it in the list
        // even when it sits just outside every polygon, or the prompt would
        // disown the name on the email.
        if ($area->name !== '' && !in_array($area->name, $names, true)) {
            array_unshift($names, $area->name);
            $names = array_slice($names, 0, $cap);
        }

        return $names;
    }

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

        $candidates = $this->anchorCandidates();

        // Each group is anchored on the town closest to it (anchorFor); groups that share
        // an anchor form one area. Groups with no candidate within the cap stand alone.
        $byTown = [];
        $standalone = [];
        $nations = [];
        foreach ($groups as $g) {
            $best = $this->anchorFor($g, $candidates, $capMiles, $nations);
            if ($best !== null) {
                $byTown[$best->key]['town'] = $best;
                $byTown[$best->key]['groups'][] = $g;
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

    /**
     * Every place an area can be anchored on: the curated towns, and the places gazetteer
     * (GeoNames, 1,000+ people). The curated list is short - it has no Oswestry, so Oswestry
     * Freegle's closest town was Wrecsam, 12.7 miles away in Wales. A place that is also a
     * curated town (same name, within 3 miles) is left out, so the two cannot split one area.
     *
     * @return array<int, object{key: string, name: string, lat: float, lng: float, population: int, curated: bool}>
     */
    protected function anchorCandidates(): array
    {
        $towns = DB::table('towns')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng'])
            ->map(fn ($t) => (object) [
                'key' => 't' . $t->id, 'name' => $t->name,
                'lat' => (float) $t->lat, 'lng' => (float) $t->lng,
                'population' => 0, 'curated' => true,
            ])
            ->all();

        if (!Schema::hasTable('places')) {
            return $towns;
        }

        $places = [];
        foreach (DB::table('places')->get(['id', 'name', 'lat', 'lng', 'population']) as $p) {
            foreach ($towns as $t) {
                if (strcasecmp($t->name, $p->name) === 0
                    && $this->haversineMiles($t->lat, $t->lng, (float) $p->lat, (float) $p->lng) < 3) {
                    continue 2;
                }
            }
            $places[] = (object) [
                'key' => 'p' . $p->id, 'name' => $p->name,
                'lat' => (float) $p->lat, 'lng' => (float) $p->lng,
                'population' => (int) $p->population, 'curated' => false,
            ];
        }

        return array_merge($towns, $places);
    }

    /**
     * The town a group is anchored on: the closest one, where a town means one people would
     * name. A group's recorded point is rarely its town centre, so plain nearest-place picks
     * neighbourhoods and villages (Birmingham became Aston, Dundee a village across the
     * Tay). In order:
     *
     *   1. a curated town within anchor_town_miles - these were chosen by hand;
     *   2. else the closest place of anchor_place_min_population or more, within
     *      anchor_place_miles;
     *   3. else the closest candidate of any size within the cap.
     *
     * Never across a national border: a candidate in another nation is skipped at every
     * step (unknown nation on either side leaves it eligible). Nations are looked up only
     * for the candidate about to be chosen, and cached by key.
     *
     * @param  array<string, string>  $nations  cache of candidate key => nation ('' unknown)
     */
    protected function anchorFor(object $g, array $candidates, float $capMiles, array &$nations): ?object
    {
        $lat = (float) $g->lat;
        $lng = (float) $g->lng;
        $groupNation = null;
        $groupNationKnown = false;

        $byDistance = [];
        foreach ($candidates as $c) {
            $d = $this->haversineMiles($lat, $lng, $c->lat, $c->lng);
            if ($d <= $capMiles) {
                $byDistance[] = [$d, $c];
            }
        }
        usort($byDistance, fn ($a, $b) => $a[0] <=> $b[0]);

        $sameNation = function (object $c) use ($lat, $lng, &$groupNation, &$groupNationKnown, &$nations): bool {
            if (!$groupNationKnown) {
                $groupNation = $this->nationAt($lat, $lng);
                $groupNationKnown = true;
            }
            if ($groupNation === null) {
                return true;
            }
            $nations[$c->key] ??= ($this->nationAt($c->lat, $c->lng) ?? '');

            return $nations[$c->key] === '' || $nations[$c->key] === $groupNation;
        };

        $townMiles = (float) config('freegle.communitynews.anchor_town_miles', 3);
        $minPopulation = (int) config('freegle.communitynews.anchor_place_min_population', 10000);
        $placeMiles = (float) config('freegle.communitynews.anchor_place_miles', 6);

        $steps = [
            fn ($d, $c) => $c->curated && $d <= $townMiles,
            fn ($d, $c) => !$c->curated && $c->population >= $minPopulation && $d <= $placeMiles,
            fn ($d, $c) => true,
        ];
        foreach ($steps as $eligible) {
            foreach ($byDistance as [$d, $c]) {
                if ($eligible($d, $c) && $sameNation($c)) {
                    return $c;
                }
            }
        }

        return null;
    }

    /**
     * The nation (England, Scotland, Wales, Northern Ireland) containing a
     * point, or null if unknown. The `CUN` country outlines are only drawn to a
     * few kilometres, so near a border they are not trusted: the detailed Welsh
     * Assembly (WAC/WAE) and Scottish Parliament (SPC/SPE) polygons decide
     * Wales and Scotland first, and a point the rough outline puts in Wales or
     * Scotland but the detailed ones do not is England if a Westminster
     * constituency (WMC) contains it. Otherwise the rough outline is used. The
     * all-encompassing United Kingdom row is excluded or it would match
     * everything. Geometries are SRID 3857 holding WGS84 degrees.
     */
    protected function nationAt(float $lat, float $lng): ?string
    {
        $in = function (array $codes) use ($lat, $lng): bool {
            $marks = implode(',', array_fill(0, count($codes), '?'));

            return (bool) DB::selectOne(
                "SELECT id FROM authorities
                 WHERE area_code IN ($marks) AND ST_Contains(polygon, ST_SRID(POINT(?, ?), 3857))
                 LIMIT 1",
                [...$codes, $lng, $lat]
            );
        };

        if ($in(['WAC', 'WAE'])) {
            return 'Wales';
        }

        if ($in(['SPC', 'SPE'])) {
            return 'Scotland';
        }

        $row = DB::selectOne(
            "SELECT name FROM authorities
             WHERE area_code = 'CUN' AND name <> 'United Kingdom'
             AND ST_Contains(polygon, ST_SRID(POINT(?, ?), 3857))
             LIMIT 1",
            [$lng, $lat]
        );
        $rough = $row ? (string) $row->name : null;

        if (($rough === 'Wales' || $rough === 'Scotland') && $in(['WMC'])) {
            return 'England';
        }

        return $rough;
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
