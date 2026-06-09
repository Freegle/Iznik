<?php

namespace Tests\Unit\Support;

use App\Support\GreatCircle;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests for GreatCircle — the port of V1's lib/GreatCircle.php.
 *
 * All tests are pure-maths: no DB, no filesystem, no external services.
 *
 * Branch coverage goals:
 *   - Zero-distance early-return
 *   - Large-distance direction-reversal (distance > metersPerDegree × 180)
 *   - direction > 180° subtraction
 *   - c < 0 (negative bearing) → dLo negated
 *   - lon < −π wrapping
 *   - lon > +π wrapping
 *   - At-pole inputs (cos(L1) approaches 0)
 */
class GreatCircleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Return type and structure
    // -------------------------------------------------------------------------

    public function test_returns_array_with_lat_and_lng_keys(): void
    {
        $result = GreatCircle::getPositionByDistance(1000.0, 0.0, 51.5, -0.1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lat', $result);
        $this->assertArrayHasKey('lng', $result);
    }

    public function test_lat_and_lng_values_are_floats(): void
    {
        $result = GreatCircle::getPositionByDistance(1000.0, 0.0, 51.5, -0.1);

        $this->assertIsFloat($result['lat']);
        $this->assertIsFloat($result['lng']);
    }

    // -------------------------------------------------------------------------
    // Zero-distance branch: early return with identical coordinates
    // -------------------------------------------------------------------------

    public function test_zero_distance_returns_identical_coordinates(): void
    {
        $result = GreatCircle::getPositionByDistance(0.0, 45.0, 51.5, -0.1);

        $this->assertSame(51.5, $result['lat']);
        $this->assertSame(-0.1, $result['lng']);
    }

    public function test_zero_distance_preserves_southern_coordinates(): void
    {
        $result = GreatCircle::getPositionByDistance(0.0, 0.0, -33.9, 151.2);

        $this->assertSame(-33.9, $result['lat']);
        $this->assertSame(151.2, $result['lng']);
    }

    public function test_zero_distance_on_equatorial_prime_meridian(): void
    {
        $result = GreatCircle::getPositionByDistance(0.0, 90.0, 0.0, 0.0);

        $this->assertSame(0.0, $result['lat']);
        $this->assertSame(0.0, $result['lng']);
    }

    // -------------------------------------------------------------------------
    // Cardinal directional travel — basic monotonicity
    // -------------------------------------------------------------------------

    public function test_bearing_north_increases_latitude(): void
    {
        $result = GreatCircle::getPositionByDistance(100_000.0, 0.0, 51.5, -0.1);

        $this->assertGreaterThan(51.5, $result['lat'], 'Northward travel must increase latitude');
    }

    public function test_bearing_south_decreases_latitude(): void
    {
        $result = GreatCircle::getPositionByDistance(100_000.0, 180.0, 51.5, -0.1);

        $this->assertLessThan(51.5, $result['lat'], 'Southward travel must decrease latitude');
    }

    public function test_bearing_east_increases_longitude(): void
    {
        $result = GreatCircle::getPositionByDistance(100_000.0, 90.0, 0.0, 0.0);

        $this->assertGreaterThan(0.0, $result['lng'], 'Eastward travel must increase longitude');
    }

    /**
     * Bearing 270° triggers the direction > 180 branch (270 − 360 = −90°),
     * and then the c < 0 branch (dLo negated → westward displacement).
     */
    public function test_bearing_west_decreases_longitude(): void
    {
        $result = GreatCircle::getPositionByDistance(100_000.0, 270.0, 0.0, 0.0);

        $this->assertLessThan(0.0, $result['lng'], 'Westward travel must decrease longitude');
    }

    // -------------------------------------------------------------------------
    // direction > 180 branch — bearings 181°–359° are adjusted by −360°
    //
    // After subtracting 360° the bearing equals its negative-angle equivalent,
    // so both must produce identical results.
    // -------------------------------------------------------------------------

    #[DataProvider('directionAdjustmentProvider')]
    public function test_bearing_over_180_matches_negative_bearing_equivalent(
        float $positiveDirection,
        float $equivalentDirection
    ): void {
        $positive   = GreatCircle::getPositionByDistance(100_000.0, $positiveDirection, 0.0, 0.0);
        $equivalent = GreatCircle::getPositionByDistance(100_000.0, $equivalentDirection, 0.0, 0.0);

        // The two bearings are mathematically identical after adjustment.
        $this->assertEqualsWithDelta($equivalent['lat'], $positive['lat'], 1e-10,
            "lat must match for bearing {$positiveDirection} deg vs {$equivalentDirection} deg");
        $this->assertEqualsWithDelta($equivalent['lng'], $positive['lng'], 1e-10,
            "lng must match for bearing {$positiveDirection} deg vs {$equivalentDirection} deg");
    }

    public static function directionAdjustmentProvider(): array
    {
        return [
            '270° = −90° (due west)'     => [270.0, -90.0],
            '181° = −179°'               => [181.0, -179.0],
            '359° = −1°'                 => [359.0, -1.0],
            '225° = −135° (southwest)'   => [225.0, -135.0],
        ];
    }

    // -------------------------------------------------------------------------
    // Large-distance branch: distance > metersPerDegree × 180 ≈ 20,001,600 m
    // -------------------------------------------------------------------------

    public function test_large_distance_returns_valid_coordinate(): void
    {
        $result = GreatCircle::getPositionByDistance(25_000_000.0, 0.0, 0.0, 0.0);

        $this->assertIsFloat($result['lat']);
        $this->assertIsFloat($result['lng']);
        $this->assertGreaterThanOrEqual(-90.0, $result['lat']);
        $this->assertLessThanOrEqual(90.0, $result['lat']);
        $this->assertGreaterThanOrEqual(-180.0, $result['lng']);
        $this->assertLessThanOrEqual(180.0, $result['lng']);
    }

    /**
     * 25,000 km heading north from the equator reverses direction
     * (distance > half-circumference) and the adjusted southward travel of
     * ~15,003 km overshoots the south pole, landing in the southern hemisphere.
     */
    public function test_large_distance_north_from_equator_lands_south_of_equator(): void
    {
        $result = GreatCircle::getPositionByDistance(25_000_000.0, 0.0, 0.0, 0.0);

        $this->assertLessThan(0.0, $result['lat'],
            'Overshooting the pole and continuing south must land south of the equator');
    }

    public function test_large_distance_does_not_crash_from_nonzero_start(): void
    {
        $result = GreatCircle::getPositionByDistance(22_000_000.0, 45.0, 51.5, -0.1);

        $this->assertIsFloat($result['lat']);
        $this->assertIsFloat($result['lng']);
        $this->assertFalse(is_nan($result['lat']));
        $this->assertFalse(is_nan($result['lng']));
    }

    // -------------------------------------------------------------------------
    // Longitude wrapping branches
    // -------------------------------------------------------------------------

    /**
     * From lon = −175°, travelling ~900 km west (bearing 270°) crosses −180°.
     * The result longitude should wrap to a positive value (the lon < −π branch).
     */
    public function test_longitude_wraps_past_antimeridian_going_west(): void
    {
        // At equator: 1° ≈ 111.12 km; 900 km ≈ 8.1°. −175° − 8.1° = −183.1° → wraps to +176.9°.
        $result = GreatCircle::getPositionByDistance(900_000.0, 270.0, 0.0, -175.0);

        $this->assertGreaterThan(0.0, $result['lng'],
            'Longitude must wrap to positive when crossing −180° going west');
    }

    /**
     * From lon = +175°, travelling ~900 km east (bearing 90°) crosses +180°.
     * The result longitude should wrap to a negative value (the lon > +π branch).
     */
    public function test_longitude_wraps_past_antimeridian_going_east(): void
    {
        // +175° + 8.1° = +183.1° → wraps to −176.9°.
        $result = GreatCircle::getPositionByDistance(900_000.0, 90.0, 0.0, 175.0);

        $this->assertLessThan(0.0, $result['lng'],
            'Longitude must wrap to negative when crossing +180° going east');
    }

    // -------------------------------------------------------------------------
    // Approximate geographic values
    // -------------------------------------------------------------------------

    /**
     * The class uses metersPerDegree = 111120.00071117 m/°; travelling exactly
     * 111,120 m due north from the equator should arrive at ≈ 1° N.
     */
    public function test_approximately_one_degree_north_from_equator(): void
    {
        $result = GreatCircle::getPositionByDistance(111_120.0, 0.0, 0.0, 0.0);

        $this->assertEqualsWithDelta(1.0, $result['lat'], 0.002,
            'Latitude after 111 120 m north from equator must be ≈ 1°');
        $this->assertEqualsWithDelta(0.0, $result['lng'], 0.001,
            'Longitude must barely change going due north');
    }

    /**
     * 500 km south from London (51.5°N) should arrive around 47°N (northern France area),
     * with essentially unchanged longitude.
     */
    public function test_500km_south_from_london_stays_on_same_meridian(): void
    {
        $result = GreatCircle::getPositionByDistance(500_000.0, 180.0, 51.5, -0.12);

        $this->assertGreaterThan(45.0, $result['lat'], 'Should still be north of 45°');
        $this->assertLessThan(51.5, $result['lat'], 'Latitude must decrease going south');
        $this->assertEqualsWithDelta(-0.12, $result['lng'], 0.05,
            'Longitude should barely change going due south');
    }

    // -------------------------------------------------------------------------
    // Round-trip symmetry
    // -------------------------------------------------------------------------

    /**
     * Going north then the same distance south must roughly return to the
     * starting point (to within rounding from the finite-distance approximation).
     */
    public function test_north_then_south_roundtrip(): void
    {
        $startLat = 48.0;
        $startLon = 2.0;
        $distance = 200_000.0;

        $north = GreatCircle::getPositionByDistance($distance, 0.0, $startLat, $startLon);
        $back  = GreatCircle::getPositionByDistance($distance, 180.0, $north['lat'], $north['lng']);

        $this->assertEqualsWithDelta($startLat, $back['lat'], 0.01, 'Round-trip latitude');
        $this->assertEqualsWithDelta($startLon, $back['lng'], 0.01, 'Round-trip longitude');
    }

    // -------------------------------------------------------------------------
    // Monotonicity — larger distance means further travel
    // -------------------------------------------------------------------------

    #[DataProvider('monotonicDistanceProvider')]
    public function test_larger_distance_reaches_higher_latitude_going_north(
        float $short,
        float $far
    ): void {
        $shortResult = GreatCircle::getPositionByDistance($short, 0.0, 51.5, -0.1);
        $farResult   = GreatCircle::getPositionByDistance($far,   0.0, 51.5, -0.1);

        $this->assertGreaterThan(
            $shortResult['lat'],
            $farResult['lat'],
            "Travelling {$far} m north must reach higher latitude than {$short} m"
        );
    }

    public static function monotonicDistanceProvider(): array
    {
        return [
            '1 km < 10 km'    => [1_000.0,   10_000.0],
            '10 km < 100 km'  => [10_000.0,  100_000.0],
            '100 km < 500 km' => [100_000.0, 500_000.0],
        ];
    }

    // -------------------------------------------------------------------------
    // Equatorial symmetry: equal distance east and west from prime meridian
    // -------------------------------------------------------------------------

    /**
     * From (lat=0, lon=0) the same distance east and west must give equal
     * and opposite longitudes (and identical latitudes), since cos(0°) = 1.
     */
    public function test_east_west_symmetry_at_equator(): void
    {
        $east = GreatCircle::getPositionByDistance(500_000.0, 90.0,  0.0, 0.0);
        $west = GreatCircle::getPositionByDistance(500_000.0, 270.0, 0.0, 0.0);

        $this->assertEqualsWithDelta($east['lat'], $west['lat'], 1e-8,
            'East and west latitudes must be equal from the equator');
        $this->assertEqualsWithDelta($east['lng'], -$west['lng'], 1e-8,
            'East and west longitudes must be symmetric about the prime meridian');
    }

    // -------------------------------------------------------------------------
    // Pole inputs: cos(L1) → 0, formula must not produce NaN / Inf
    // -------------------------------------------------------------------------

    public function test_starting_at_north_pole_returns_finite_result(): void
    {
        $result = GreatCircle::getPositionByDistance(1_000_000.0, 180.0, 90.0, 0.0);

        $this->assertIsFloat($result['lat']);
        $this->assertIsFloat($result['lng']);
        $this->assertFalse(is_nan($result['lat']),      'lat must not be NaN when starting at north pole');
        $this->assertFalse(is_nan($result['lng']),      'lng must not be NaN when starting at north pole');
        $this->assertFalse(is_infinite($result['lat']), 'lat must not be Inf when starting at north pole');
        $this->assertFalse(is_infinite($result['lng']), 'lng must not be Inf when starting at north pole');
    }

    public function test_starting_at_south_pole_returns_finite_result(): void
    {
        $result = GreatCircle::getPositionByDistance(1_000_000.0, 0.0, -90.0, 0.0);

        $this->assertIsFloat($result['lat']);
        $this->assertIsFloat($result['lng']);
        $this->assertFalse(is_nan($result['lat']),  'lat must not be NaN when starting at south pole');
        $this->assertFalse(is_nan($result['lng']),  'lng must not be NaN when starting at south pole');
    }

    // -------------------------------------------------------------------------
    // Determinism
    // -------------------------------------------------------------------------

    public function test_repeated_calls_with_same_arguments_return_identical_results(): void
    {
        $a = GreatCircle::getPositionByDistance(55_000.0, 45.0, 52.0, 1.0);
        $b = GreatCircle::getPositionByDistance(55_000.0, 45.0, 52.0, 1.0);

        $this->assertSame($a['lat'], $b['lat'], 'lat must be deterministic');
        $this->assertSame($a['lng'], $b['lng'], 'lng must be deterministic');
    }

    // -------------------------------------------------------------------------
    // Compass-direction table — all eight principal and inter-cardinal bearings
    // -------------------------------------------------------------------------

    /**
     * @param  string $latRelation  'greater', 'less', or 'same' relative to start (51.5°N).
     * @param  string $lngRelation  'greater', 'less', or 'same' relative to start (−0.1°E).
     */
    #[DataProvider('compassDirectionProvider')]
    public function test_compass_direction_moves_position_correctly(
        float $direction,
        string $latRelation,
        string $lngRelation
    ): void {
        $startLat = 51.5;
        $startLng = -0.1;
        $distance = 100_000.0;

        $result = GreatCircle::getPositionByDistance($distance, $direction, $startLat, $startLng);

        if ($latRelation === 'greater') {
            $this->assertGreaterThan($startLat, $result['lat'],
                "Bearing {$direction}° must move latitude north");
        } elseif ($latRelation === 'less') {
            $this->assertLessThan($startLat, $result['lat'],
                "Bearing {$direction}° must move latitude south");
        } else {
            $this->assertEqualsWithDelta($startLat, $result['lat'], 0.2,
                "Bearing {$direction}° should barely change latitude");
        }

        if ($lngRelation === 'greater') {
            $this->assertGreaterThan($startLng, $result['lng'],
                "Bearing {$direction}° must move longitude east");
        } elseif ($lngRelation === 'less') {
            $this->assertLessThan($startLng, $result['lng'],
                "Bearing {$direction}° must move longitude west");
        } else {
            $this->assertEqualsWithDelta($startLng, $result['lng'], 0.2,
                "Bearing {$direction}° should barely change longitude");
        }
    }

    public static function compassDirectionProvider(): array
    {
        return [
            'due north (0°)'      => [  0.0, 'greater', 'same'],
            'due east (90°)'      => [ 90.0, 'same',    'greater'],
            'due south (180°)'    => [180.0, 'less',    'same'],
            'due west (270°)'     => [270.0, 'same',    'less'],
            'northeast (45°)'     => [ 45.0, 'greater', 'greater'],
            'southeast (135°)'    => [135.0, 'less',    'greater'],
            'southwest (225°)'    => [225.0, 'less',    'less'],
            'northwest (315°)'    => [315.0, 'greater', 'less'],
        ];
    }
}
