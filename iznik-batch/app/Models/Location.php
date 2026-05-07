<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

    /**
     * Find the closest full postcode to a given lat/lng.
     *
     * Uses an expanding spatial bounding box search for efficiency - starts small
     * and doubles until a result is found or the max scan radius is reached.
     *
     * @return array|null  Array with id, name, areaid, lat, lng, and optionally 'area' info, or null if not found
     */
    public static function closestPostcode(float $lat, float $lng): ?array
    {
        $srid = config('freegle.srid', 3857);
        $scan = 0.00001953125;

        while ($scan <= 0.2) {
            $swlat = $lat - $scan;
            $nelat = $lat + $scan;
            $swlng = $lng - $scan;
            $nelng = $lng + $scan;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            $point = "POINT($lng $lat)";

            $result = LocationSpatial::query()
                ->join('locations', 'locations.id', '=', 'locations_spatial.locationid')
                ->selectRaw('locations.id, locations.name, locations.areaid, locations.lat, locations.lng')
                ->whereRaw("MBRContains(ST_Envelope(ST_GeomFromText(?, ?)), locations_spatial.geometry)", [$poly, $srid])
                ->where('locations.type', 'Postcode')
                ->whereRaw("LOCATE(' ', locations.name) > 0")
                ->orderByRaw("ST_distance(locations_spatial.geometry, ST_GeomFromText(?, ?)) ASC", [$point, $srid])
                ->orderByRaw("CASE WHEN ST_Dimension(locations_spatial.geometry) < 2 THEN 0 ELSE ST_AREA(locations_spatial.geometry) END ASC")
                ->limit(1)
                ->first();

            if ($result) {
                $ret = $result->toArray();

                if ($ret['areaid']) {
                    $area = self::find($ret['areaid']);

                    if ($area) {
                        $ret['area'] = $area->toArray();
                    }

                    unset($ret['areaid']);
                }

                return $ret;
            }

            $scan *= 2;
        }

        return null;
    }

    /**
     * Get the public representation of this location.
     *
     * Ported from iznik-server Location::$publicatts + Entity::getPublic().
     */
    public function getPublic(): array
    {
        return $this->only(self::PUBLIC_ATTS);
    }
}
