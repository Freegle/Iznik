<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Location extends Model
{
    protected $table = 'locations';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

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
}
