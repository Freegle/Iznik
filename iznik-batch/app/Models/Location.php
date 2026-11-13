<?php

namespace App\Models;

use App\Database\Expressions\Comparison;
use App\Database\Expressions\Concat;
use App\Database\Expressions\Haversine;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StGeomFromText;
use App\Database\Expressions\Value;
use App\Services\SpatialQueryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

class Location extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    // Fields exposed by getPublic() - mirrors the legacy V1 PHP Location::$publicatts.
    private const PUBLIC_ATTS = ['id', 'osm_id', 'name', 'type', 'popularity', 'postcodeid', 'areaid', 'lat', 'lng', 'maxdimension'];

    protected $table = 'locations';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'osm_place' => 'boolean',
        'osm_amenity' => 'boolean',
        'osm_shop' => 'boolean',
    ];

    /**
     * Nearest full postcode to a point, via the spatial server's KNN index
     * (the "postcodes" point dataset). Returns null if the spatial server has
     * nothing nearby or is unreachable.
     */
    public static function closestPostcode(float $lat, float $lng): ?object
    {
        $ids = (new SpatialQueryService())->nearestIds('postcodes', $lat, $lng, 1);
        if (empty($ids)) {
            return null;
        }

        return DB::table('locations')->where('id', $ids[0])
            ->select('id', 'name', 'lat', 'lng')
            ->first();
    }

    /**
     * "AB10 1XG (Gilcomston)" or just "AB10 1XG" if no area — used for the ripple
     * proximity ("quicker to get to") moderator note. Does not change
     * closestPostcode()'s existing return shape; this is a separate helper with its
     * own (postcode, area) join.
     */
    public static function describeNearest(float $lat, float $lng): ?string
    {
        $ids = (new SpatialQueryService())->nearestIds('postcodes', $lat, $lng, 1);
        if (empty($ids)) {
            return null;
        }

        $loc = DB::table('locations as p')
            ->leftJoin('locations as a', 'a.id', '=', 'p.areaid')
            ->where('p.id', $ids[0])
            ->select('p.name as postcode', 'a.name as area')
            ->first();
        if (!$loc) {
            return null;
        }

        return $loc->area ? "{$loc->postcode} ({$loc->area})" : $loc->postcode;
    }

    /**
     * Like describeNearest(), but names the point with a postcode that actually
     * lies inside the given group's area polygon (groups.polyindex).
     *
     * The ripple "quicker to get to" note picks in-group points near the group's
     * edge; naming them with the single globally-nearest postcode landed on a
     * NEIGHBOURING group's postcode, so a Hackney mod saw Islington/Newham
     * postcodes "justifying" the ripple (Discourse 9808/583). Take the nearest few
     * postcodes and keep the nearest one that is inside the group; if none are (or
     * the group has no usable polygon) fall back to the unconstrained nearest so we
     * still produce a note.
     */
    public static function describeNearestInGroup(float $lat, float $lng, int $groupid): ?string
    {
        $ids = (new SpatialQueryService())->nearestIds('postcodes', $lat, $lng, 10);
        if (empty($ids)) {
            return null;
        }

        $srid = (int) config('freegle.srid', 3857);

        // Point built from the postcode's own lat/lng as POINT(lng lat) in the engine's SRID,
        // matching groupsNear()'s containment test (so no dependence on the SRID stored on
        // locations.geometry). The KNN nearest-first order is kept by picking in $ids order.
        $inside = DB::table('locations as p')
            ->leftJoin('locations as a', 'a.id', '=', 'p.areaid')
            ->join('groups as g', 'g.id', '=', DB::raw((int) $groupid))
            ->whereIn('p.id', array_map('intval', $ids))
            ->whereNotNull('p.lat')
            ->whereNotNull('p.lng')
            ->whereNotNull('g.polyindex')
            ->where(new StContains(
                'g.polyindex',
                new StGeomFromText(new Concat(Value::of('POINT('), 'p.lng', Value::of(' '), 'p.lat', Value::of(')')), $srid)
            ))
            ->select('p.id', 'p.name as postcode', 'a.name as area')
            ->get()
            ->keyBy('id');

        $loc = null;
        foreach ($ids as $id) {
            if ($inside->has((int) $id)) {
                $loc = $inside->get((int) $id);
                break;
            }
        }

        if ($loc) {
            return $loc->area ? "{$loc->postcode} ({$loc->area})" : $loc->postcode;
        }

        // No candidate inside the group (or no usable polygon) — name the nearest
        // overall so we still produce a note rather than none.
        $fallback = DB::table('locations as p')
            ->leftJoin('locations as a', 'a.id', '=', 'p.areaid')
            ->where('p.id', $ids[0])
            ->select('p.name as postcode', 'a.name as area')
            ->first();
        if (!$fallback) {
            return null;
        }

        return $fallback->area ? "{$fallback->postcode} ({$fallback->area})" : $fallback->postcode;
    }

    public static function findByName(string $name): ?int
    {
        return static::getByName($name)?->id;
    }

    public static function getByName(string $name): ?object
    {
        $canon = strtolower(preg_replace("/[^A-Za-z0-9]/", '', $name));
        return DB::table('locations')->where('canon', 'LIKE', $canon)->first();
    }

    public static function groupsNear(float $lat, float $lng, int $radiusMiles = 50, int $limit = 10): array
    {
        $srid     = config('freegle.srid', 3857);
        $pointWkt = "POINT($lng $lat)";

        // Polygon-containment check: if the point lies inside one or more group
        // polyindex polygons, those groups are authoritative and beat the centroid-
        // distance heuristic (V1 parity; fixes Discourse #9763 where a group with
        // a close centroid but non-containing polyindex shadowed the correct group).
        // keep-raw: ST_Contains/ST_GeomFromText/haversine() have no builder method.
        $containing = DB::table('groups')
            ->where('publish', 1)
            ->where('listable', 1)
            ->where(new StContains('polyindex', new StGeomFromText(Value::of($pointWkt), $srid)))
            ->orderBy(new Haversine('lat', 'lng', Value::of($lat), Value::of($lng)))
            ->limit($limit)
            ->pluck('id')
            ->all();

        if (!empty($containing)) {
            return $containing;
        }

        // No group polygon contains the point; fall back to centroid distance.
        // keep-raw: haversine() has no builder method.
        return DB::table('groups')
            ->where('publish', 1)
            ->where('listable', 1)
            ->where(new Comparison(new Haversine('lat', 'lng', Value::of($lat), Value::of($lng)), '<', Value::of($radiusMiles)))
            ->orderBy(new Haversine('lat', 'lng', Value::of($lat), Value::of($lng)))
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * The bracket a post subject carries for this location, as the Go API builds it
     * for every post made on Freegle (message.constructLocationString): the area name
     * and the outward half of the postcode, "Stoneygate LE2". A postcode with no area
     * gives the outward half alone, and a location that is not a postcode gives its
     * name. Null when the id is not in `locations`.
     */
    public static function subjectArea(int $locationId): ?string
    {
        $loc = DB::table('locations as l')
            ->leftJoin('locations as a', 'a.id', '=', 'l.areaid')
            ->where('l.id', $locationId)
            ->select('l.name', 'l.type', 'a.name as area')
            ->first();

        if (!$loc || $loc->name === null || $loc->name === '') {
            return null;
        }

        if ($loc->type !== 'Postcode') {
            return $loc->name;
        }

        $outward = $loc->name;
        if (($space = strpos($outward, ' ')) > 0) {
            $outward = substr($outward, 0, $space);
        }

        return $loc->area ? "{$loc->area} {$outward}" : $outward;
    }
}
