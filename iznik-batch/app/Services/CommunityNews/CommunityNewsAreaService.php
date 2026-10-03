<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the `authorities` table (Counties and Unitary Authorities) into
 * Community News "areas", one area per authority.
 *
 * The research call searches around the area's NAME, and local news supply is
 * organised by named place (a paper's patch, a council's what's-on), so the
 * area unit is a real, searchable authority, not a distance blob. An area is
 * keyed on `authorityid` so a re-run upserts the same row and keeps its
 * cadence timers. An authority that has since been removed from the table (a
 * boundary reorganisation) takes its area with it, cascading to the area's
 * items.
 */
class CommunityNewsAreaService
{
    /** Earth radius in miles (mean). */
    private const EARTH_MILES = 3958.7559;

    /**
     * The places an area actually covers, biggest first.
     *
     * An authority can be large (Fife runs Dunfermline to St Andrews), so
     * research written against the authority's name alone is often about one
     * corner of it. Ask the gazetteer instead: every place whose point falls
     * inside the area's authority, biggest first. Median 6 per area, p90 14,
     * hence the cap - a prompt listing forty villages buys nothing and crowds
     * out the instructions.
     *
     * @return array<int, string>
     */
    public function placesCovered(CommunityNewsArea $area, ?int $cap = null): array
    {
        $cap = $cap ?? (int) config('freegle.communitynews.places_per_area', 8);
        if (! $area->authorityid || $cap < 1) {
            return [];
        }

        // The research job runs hourly, so it can land between this code
        // deploying and the migration running. Degrade to the area name rather
        // than fataling - but say so, because a guard like this that stays
        // quiet is how a missing table survives for months.
        if (! Schema::hasTable('places')) {
            Log::warning('CommunityNews: no places table, so areas cover only their own name');

            return [];
        }

        $names = DB::table('places')
            ->crossJoin('authorities')
            ->where('authorities.id', (int) $area->authorityid)
            ->whereRaw('ST_Contains(authorities.polygon, places.position)')
            ->groupBy('places.id', 'places.name', 'places.population')
            ->orderByDesc('places.population')
            ->limit($cap)
            ->pluck('places.name')
            ->all();

        // The area is named after its authority; keep that name in the list
        // even when no place of the same name exists, or the prompt would
        // disown the name on the email.
        if ($area->name !== '' && ! in_array($area->name, $names, true)) {
            array_unshift($names, $area->name);
            $names = array_slice($names, 0, $cap);
        }

        return $names;
    }

    /**
     * Recompute areas from the current `authorities` table and upsert them.
     * Stale areas (whose authority no longer exists) are removed, cascading
     * to their items.
     *
     * @return Collection<int, CommunityNewsArea>
     */
    public function rebuildAreas(): Collection
    {
        $authorities = DB::table('authorities')
            ->select([
                'id',
                'name',
                DB::raw('ST_Y(ST_Centroid(polygon)) AS lat'),
                DB::raw('ST_X(ST_Centroid(polygon)) AS lng'),
            ])
            ->get();

        $areas = collect();
        $seen = [];

        foreach ($authorities as $a) {
            $seen[] = (int) $a->id;

            $areas->push(CommunityNewsArea::updateOrCreate(
                ['authorityid' => $a->id],
                [
                    'name' => $a->name,
                    'lat' => round((float) $a->lat, 6),
                    'lng' => round((float) $a->lng, 6),
                ]
            ));
        }

        // authorityid IS NULL catches rows left over from before the
        // authorityid column was backfilled; NOT IN alone would never match
        // them, since a NULL comparison is never true.
        $idList = implode(',', array_map('intval', $seen ?: [0]));
        CommunityNewsArea::whereRaw("authorityid IS NULL OR authorityid NOT IN ({$idList})")->delete();

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
}
