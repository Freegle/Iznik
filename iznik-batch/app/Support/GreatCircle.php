<?php

namespace App\Support;

/**
 * Great-circle position maths, ported verbatim from iznik-server lib/GreatCircle.php.
 *
 * Used to build the spatial bounding box for the newsfeed digest, so the box
 * matches V1's exactly (NE corner at bearing 45°, SW at 225°, given a distance
 * in metres).
 */
class GreatCircle
{
    private static function hav(float $x): float
    {
        return (1.0 - cos($x)) * 0.5;
    }

    private static function ngt1(float $x): float
    {
        return $x > 1.0 ? 1.0 : ($x < -1.0 ? -1.0 : $x);
    }

    private static function ahav(float $x): float
    {
        return acos(self::ngt1(1.0 - $x * 2.0));
    }

    private static function sec(float $x): float
    {
        return 1.0 / cos($x);
    }

    private static function csc(float $x): float
    {
        return 1.0 / sin($x);
    }

    /**
     * Return the lat/lng reached by travelling $distance metres along bearing
     * $direction (degrees) from ($lat, $lon).
     *
     * @return array{lng: float, lat: float}
     */
    public static function getPositionByDistance(float $distance, float $direction, float $lat, float $lon): array
    {
        if ((float) $distance === 0.0) {
            return ['lng' => $lon, 'lat' => $lat];
        }

        $metersPerDegree = 111120.00071117;
        $degreesPerMeter = 1.0 / $metersPerDegree;
        $radiansPerDegree = pi() / 180.0;
        $degreesPerRadian = 180.0 / pi();

        if ($distance > $metersPerDegree * 180) {
            $direction -= 180.0;
            if ($direction < 0.0) {
                $direction += 360.0;
            }
            $distance = $metersPerDegree * 360.0 - $distance;
        }

        if ($direction > 180.0) {
            $direction -= 360.0;
        }

        $c = $direction * $radiansPerDegree;
        $d = $distance * $degreesPerMeter * $radiansPerDegree;
        $L1 = $lat * $radiansPerDegree;
        $lon *= $radiansPerDegree;
        $coL1 = (90.0 - $lat) * $radiansPerDegree;
        $coL2 = self::ahav(self::hav($c) / (self::sec($L1) * self::csc($d)) + self::hav($d - $coL1));
        $L2 = (pi() / 2) - $coL2;
        $l = $L2 - $L1;

        $dLo = (cos($L1) * cos($L2));
        if ($dLo != 0.0) {
            $dLo = self::ahav((self::hav($d) - self::hav($l)) / $dLo);
        }

        if ($c < 0.0) {
            $dLo = -$dLo;
        }

        $lon += $dLo;
        if ($lon < -pi()) {
            $lon += 2 * pi();
        } elseif ($lon > pi()) {
            $lon -= 2 * pi();
        }

        return [
            'lng' => $lon * $degreesPerRadian,
            'lat' => $L2 * $degreesPerRadian,
        ];
    }
}
