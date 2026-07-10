<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\User;
use App\Services\Ripple\DistancePreferenceFilter;
use Tests\TestCase;

/**
 * Pure, dependency-free unit tests for the shared distance-preference filter helper
 * (see docs/superpowers/specs/2026-07-01-distance-preference-email-filtering-design.md).
 * Mirrors DigestPostScorerTest's style: bare `new DistancePreferenceFilter()`, no DB.
 */
class DistancePreferenceFilterTest extends TestCase
{
    private function filter(): DistancePreferenceFilter
    {
        return new DistancePreferenceFilter();
    }

    /** Build an unsaved User model with the given settings array (no DB touched). */
    private function userWithSettings(?array $settings): User
    {
        $user = new User();
        $user->settings = $settings;

        return $user;
    }

    public function test_sentinel_constant_matches_go_and_js_browse_distance_unlimited(): void
    {
        // Must stay byte-identical to iznik-nuxt3/constants.js BROWSE_DISTANCE_UNLIMITED
        // and iznik-server-go/isochrone/message.go BrowseDistanceUnlimited.
        $this->assertSame(9007199254740991, DistancePreferenceFilter::DISTANCE_UNLIMITED);
    }

    public function test_max_distance_miles_returns_sentinel_when_settings_absent(): void
    {
        $user = $this->userWithSettings(null);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_key_missing(): void
    {
        $user = $this->userWithSettings(['simplemail' => 'Basic']);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_null(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => null]);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_non_numeric(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => 'not-a-number']);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_zero(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => 0]);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_negative(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => -5]);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_at_the_sentinel(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => DistancePreferenceFilter::DISTANCE_UNLIMITED]);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_sentinel_when_above_the_sentinel(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => DistancePreferenceFilter::DISTANCE_UNLIMITED + 1]);
        $this->assertSame((float) DistancePreferenceFilter::DISTANCE_UNLIMITED, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_configured_value(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => 2.5]);
        $this->assertSame(2.5, $this->filter()->maxDistanceMiles($user));
    }

    public function test_max_distance_miles_returns_configured_integer_value(): void
    {
        $user = $this->userWithSettings(['browseMaxDistance' => 10]);
        $this->assertSame(10.0, $this->filter()->maxDistanceMiles($user));
    }

    public function test_passes_within_range(): void
    {
        $this->assertTrue($this->filter()->passes(1.0, 2.0, false));
    }

    public function test_passes_at_exact_boundary_is_inclusive(): void
    {
        $this->assertTrue($this->filter()->passes(2.0, 2.0, false));
    }

    public function test_passes_beyond_range_fails(): void
    {
        $this->assertFalse($this->filter()->passes(2.01, 2.0, false));
    }

    public function test_passes_sentinel_always_passes_regardless_of_distance(): void
    {
        $this->assertTrue($this->filter()->passes(1000000.0, (float) DistancePreferenceFilter::DISTANCE_UNLIMITED, false));
    }

    public function test_passes_own_post_always_passes_regardless_of_distance(): void
    {
        $this->assertTrue($this->filter()->passes(1000000.0, 2.0, true));
    }

    // ─── OUTBOUND (author-side) distance preference ─────────────────────
    // passesBothPreferences enforces BOTH the recipient's inbound cap AND the post
    // author's outbound cap on the SAME recipient<->post distance: a post is shown
    // only to people within the author's chosen distance of it, as well as within
    // the recipient's own chosen distance. Own posts always pass.

    public function test_passes_both_within_both_limits(): void
    {
        $this->assertTrue($this->filter()->passesBothPreferences(1.0, 2.0, 3.0, false));
    }

    public function test_passes_both_fails_when_beyond_author_limit_only(): void
    {
        // The recipient is happy to see far posts (cap 50) but the author capped at 2.
        $this->assertFalse($this->filter()->passesBothPreferences(10.0, 50.0, 2.0, false));
    }

    public function test_passes_both_fails_when_beyond_recipient_limit_only(): void
    {
        $this->assertFalse($this->filter()->passesBothPreferences(10.0, 2.0, 50.0, false));
    }

    public function test_passes_both_fails_when_beyond_both_limits(): void
    {
        $this->assertFalse($this->filter()->passesBothPreferences(100.0, 2.0, 3.0, false));
    }

    public function test_passes_both_author_boundary_is_inclusive(): void
    {
        $this->assertTrue($this->filter()->passesBothPreferences(2.0, 50.0, 2.0, false));
    }

    public function test_passes_both_own_post_bypasses_both_limits(): void
    {
        $this->assertTrue($this->filter()->passesBothPreferences(1000000.0, 2.0, 2.0, true));
    }

    public function test_passes_both_unlimited_author_defers_to_recipient(): void
    {
        $unlimited = (float) DistancePreferenceFilter::DISTANCE_UNLIMITED;
        $this->assertTrue($this->filter()->passesBothPreferences(10.0, 50.0, $unlimited, false));
        $this->assertFalse($this->filter()->passesBothPreferences(10.0, 5.0, $unlimited, false));
    }

    public function test_distance_miles_is_zero_at_the_same_point(): void
    {
        $this->assertSame(0.0, $this->filter()->distanceMiles(51.5, -0.1, 51.5, -0.1));
    }

    public function test_distance_miles_matches_known_reference_value(): void
    {
        // London (51.5074, -0.1278) -> Paris (48.8566, 2.3522): commonly cited
        // great-circle distance is ~213 miles. Allow a few miles of tolerance
        // for the spherical (not ellipsoid) approximation.
        $miles = $this->filter()->distanceMiles(51.5074, -0.1278, 48.8566, 2.3522);
        $this->assertEqualsWithDelta(213.0, $miles, 5.0);
    }
}
