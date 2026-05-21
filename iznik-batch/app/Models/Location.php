<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

class Location extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    // Fields exposed by getPublic() - mirrors iznik-server Location::$publicatts.
    private const PUBLIC_ATTS = ['id', 'osm_id', 'name', 'type', 'popularity', 'gridid', 'postcodeid', 'areaid', 'lat', 'lng', 'maxdimension'];

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

    public static function closestPostcode(float $lat, float $lng): ?object
    {
        $srid = config('freegle.srid', 3857);
        $scan = 0.00001953125;

        do {
            $swlat = $lat - $scan;
            $nelat = $lat + $scan;
            $swlng = $lng - $scan;
            $nelng = $lng + $scan;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";

            $locs = DB::select(
                "SELECT locations.id, locations.name, locations.lat, locations.lng
                 FROM locations_spatial
                 INNER JOIN locations ON locations.id = locations_spatial.locationid
                 WHERE MBRContains(ST_Envelope(ST_GeomFromText(?, ?)), locations_spatial.geometry)
                   AND locations.type = 'Postcode'
                   AND LOCATE(' ', locations.name) > 0
                 ORDER BY ST_distance(locations_spatial.geometry, ST_GeomFromText(?, ?)) ASC
                 LIMIT 1",
                [$poly, $srid, "POINT($lng $lat)", $srid]
            );

            if (count($locs) === 1) {
                return $locs[0];
            }

            $scan *= 2;
        } while ($scan <= 0.2);

        return null;
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
        $rows = DB::select(
            "SELECT id
             FROM `groups`
             WHERE publish = 1 AND listable = 1
               AND haversine(lat, lng, ?, ?) < ?
             ORDER BY haversine(lat, lng, ?, ?) ASC
             LIMIT ?",
            [$lat, $lng, $radiusMiles, $lat, $lng, $limit]
        );

        return array_column($rows, 'id');
    }
}
