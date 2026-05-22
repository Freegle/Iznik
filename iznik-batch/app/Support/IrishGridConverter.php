<?php

namespace App\Support;

/**
 * Converts Irish Grid (EPSG:29903 / TM75) eastings+northings to WGS84 lat/lng.
 *
 * Uses the standard OS inverse-Transverse-Mercator formula on the Airy Modified
 * ellipsoid, then applies the TM75 → ETRS89 Helmert 7-parameter transformation
 * (EPSG:1954) and a small ETRS89→WGS84 identity (negligible for this use-case).
 *
 * Helmert parameters (TM75 → WGS84, EPSG:1954):
 *   dx = +482.530 m, dy = −130.596 m, dz = +564.557 m
 *   rx = −1.042″,   ry = −0.214″,   rz = −0.631″
 *   ds = +8.150 ppm
 *
 * Airy Modified ellipsoid:
 *   a = 6_377_340.189 m, b = 6_356_034.448 m
 *
 * Irish Grid projection parameters:
 *   Central meridian λ₀ = −8° (−0.139626 rad)
 *   Latitude of origin φ₀ = 53.5° (0.933751 rad)
 *   False easting  E₀ = 200 000 m
 *   False northing N₀ = 250 000 m
 *   Scale factor   k₀ = 1.000035
 */
class IrishGridConverter
{
    // Airy Modified ellipsoid
    private const AM_A  = 6_377_340.189;
    private const AM_B  = 6_356_034.448;
    private const AM_E2 = 0.006670539980774;  // 1 - (AM_B/AM_A)²

    // Irish Grid projection constants
    private const IG_LAM0 = -0.13962634016;  // −8° in radians
    private const IG_PHI0 = 0.93375297612;   // 53.5° in radians
    private const IG_E0   = 200_000.0;
    private const IG_N0   = 250_000.0;
    private const IG_K0   = 1.000035;

    // Helmert: TM75 → WGS84  (EPSG:1954, in metres / arc-seconds / ppm)
    private const H_DX  =  482.530;
    private const H_DY  = -130.596;
    private const H_DZ  =  564.557;
    private const H_RX  = -1.042;   // arc-seconds
    private const H_RY  = -0.214;
    private const H_RZ  = -0.631;
    private const H_DS  =  8.150;   // ppm

    /**
     * Convert Irish Grid (E, N) to WGS84 (lat, lng) in decimal degrees.
     * Returns [lat, lng] or null if conversion fails.
     */
    public function toWgs84(float $easting, float $northing): ?array
    {
        [$phiTm75, $lamTm75] = $this->igToGeodetic($easting, $northing);

        [$x, $y, $z] = $this->geodeticToCartesian($phiTm75, $lamTm75, self::AM_A, self::AM_E2);

        [$xWgs, $yWgs, $zWgs] = $this->helmertTransform($x, $y, $z);

        // WGS84 ellipsoid
        $a   = 6_378_137.0;
        $e2  = 0.00669437999014;

        [$lat, $lng] = $this->cartesianToGeodetic($xWgs, $yWgs, $zWgs, $a, $e2);

        return [rad2deg($lat), rad2deg($lng)];
    }

    /**
     * Inverse Irish Grid (Transverse Mercator) → geodetic (radians) on Airy Modified.
     */
    private function igToGeodetic(float $E, float $N): array
    {
        $a  = self::AM_A;
        $e2 = self::AM_E2;
        $k0 = self::IG_K0;
        $E0 = self::IG_E0;
        $N0 = self::IG_N0;

        $n  = ($a - self::AM_B) / ($a + self::AM_B);
        $n2 = $n ** 2;
        $n3 = $n ** 3;
        $n4 = $n ** 4;

        $M0 = $a * (
            (1 + $n + 5/4 * $n2 + 5/4 * $n3) * self::IG_PHI0
          - (3*$n + 3*$n2 + 21/8 * $n3) * sin(self::IG_PHI0) * cos(self::IG_PHI0)
          + (15/8 * $n2 + 15/8 * $n3) * sin(2*self::IG_PHI0) * cos(2*self::IG_PHI0)
          - (35/24 * $n3) * sin(3*self::IG_PHI0) * cos(3*self::IG_PHI0)
        );

        $phi = (($N - $N0) + $k0 * $M0) / ($k0 * $a);

        for ($i = 0; $i < 100; $i++) {
            $M = $a * (
                (1 + $n + 5/4 * $n2 + 5/4 * $n3) * $phi
              - (3*$n + 3*$n2 + 21/8 * $n3) * sin($phi) * cos($phi)
              + (15/8 * $n2 + 15/8 * $n3) * sin(2*$phi) * cos(2*$phi)
              - (35/24 * $n3) * sin(3*$phi) * cos(3*$phi)
            );
            $deltaPhi = (($N - $N0) - $k0 * ($M - $M0)) / ($k0 * $a);
            $phi += $deltaPhi;
            if (abs($deltaPhi) < 1e-12) {
                break;
            }
        }

        $sinPhi = sin($phi);
        $cosPhi = cos($phi);
        $tanPhi = tan($phi);

        $nu  = $a / sqrt(1 - $e2 * $sinPhi ** 2);
        $rho = $a * (1 - $e2) / (1 - $e2 * $sinPhi ** 2) ** 1.5;
        $eta2 = $nu / $rho - 1;

        $x = ($E - $E0) / ($k0 * $nu);

        $lat = $phi
            - ($tanPhi / (2 * $rho * $nu)) * $x**2
            + ($tanPhi / (24 * $rho * $nu**3)) * (5 + 3*$tanPhi**2 + $eta2 - 9*$tanPhi**2*$eta2) * $x**4
            - ($tanPhi / (720 * $rho * $nu**5)) * (61 + 90*$tanPhi**2 + 45*$tanPhi**4) * $x**6;

        $sec  = 1 / $cosPhi;
        $lng  = self::IG_LAM0
            + $sec / $nu * $x
            - $sec / (6 * $nu**3) * ($nu/$rho + 2*$tanPhi**2) * $x**3
            + $sec / (120 * $nu**5) * (5 + 28*$tanPhi**2 + 24*$tanPhi**4) * $x**5
            - $sec / (5040 * $nu**7) * (61 + 662*$tanPhi**2 + 1320*$tanPhi**4 + 720*$tanPhi**6) * $x**7;

        return [$lat, $lng];
    }

    /**
     * Geodetic (radians) → ECEF Cartesian (metres) on given ellipsoid.
     */
    private function geodeticToCartesian(float $phi, float $lam, float $a, float $e2): array
    {
        $nu = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        $x  = $nu * cos($phi) * cos($lam);
        $y  = $nu * cos($phi) * sin($lam);
        $z  = $nu * (1 - $e2) * sin($phi);
        return [$x, $y, $z];
    }

    /**
     * Helmert 7-parameter transform (TM75 → WGS84).
     * Arc-second rotations converted to radians internally.
     */
    private function helmertTransform(float $x, float $y, float $z): array
    {
        $s  = self::H_DS * 1e-6;
        $rx = deg2rad(self::H_RX / 3600);
        $ry = deg2rad(self::H_RY / 3600);
        $rz = deg2rad(self::H_RZ / 3600);

        $xNew = self::H_DX + (1 + $s) * ($x         + (-$rz)*$y + $ry*$z);
        $yNew = self::H_DY + (1 + $s) * ($rz*$x    + $y          + (-$rx)*$z);
        $zNew = self::H_DZ + (1 + $s) * ((-$ry)*$x + $rx*$y      + $z);

        return [$xNew, $yNew, $zNew];
    }

    /**
     * ECEF Cartesian → geodetic (radians) on given ellipsoid (Bowring iteration).
     */
    private function cartesianToGeodetic(float $x, float $y, float $z, float $a, float $e2): array
    {
        $lam = atan2($y, $x);
        $p   = sqrt($x**2 + $y**2);
        $b   = $a * sqrt(1 - $e2);
        $ep2 = ($a**2 - $b**2) / $b**2;

        $phi = atan2($z, $p * (1 - $e2));

        for ($i = 0; $i < 10; $i++) {
            $nu   = $a / sqrt(1 - $e2 * sin($phi) ** 2);
            $phiN = atan2($z + $e2 * $nu * sin($phi), $p);
            if (abs($phiN - $phi) < 1e-12) {
                $phi = $phiN;
                break;
            }
            $phi = $phiN;
        }

        return [$phi, $lam];
    }
}
