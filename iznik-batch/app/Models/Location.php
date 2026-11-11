<?php

namespace App\Models;

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

    public static function findByName(string $name): ?int
    {
        return static::getByName($name)?->id;
    }

    public static function getByName(string $name): ?object
    {
        $canon = strtolower(preg_replace("/[^A-Za-z0-9]/", '', $name));
        return DB::table('locations')->where('canon', 'LIKE', $canon)->first();
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
