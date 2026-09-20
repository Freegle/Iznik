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

    /**
     * The band default (browse:backfill-max-distance) is INBOUND only. It lives in its own
     * key because browseMaxDistance also caps how far away other people see the member's
     * posts - see DensityService for why the cap belongs to the recipient.
     */
    public function test_band_default_applies_when_the_member_has_not_chosen(): void
    {
        $user = $this->userWithSettings(['browseReachMaxDistance' => 7.4]);
        $this->assertSame(7.4, $this->filter()->maxDistanceMiles($user));
    }

    public function test_an_explicit_choice_beats_the_band_default_even_when_wider(): void
    {
        // Someone who moved the slider has said what they want; the default is only a default.
        $user = $this->userWithSettings([
            'browseMaxDistance' => 22.0,
            'browseReachMaxDistance' => 7.4,
        ]);
        $this->assertSame(22.0, $this->filter()->maxDistanceMiles($user));
    }

    public function test_an_explicit_unlimited_choice_beats_a_narrower_band_default(): void
    {
        $user = $this->userWithSettings([
            'browseMaxDistance' => DistancePreferenceFilter::DISTANCE_UNLIMITED,
            'browseReachMaxDistance' => 7.4,
        ]);
        $this->assertSame(
            (float) DistancePreferenceFilter::DISTANCE_UNLIMITED,
            $this->filter()->maxDistanceMiles($user)
        );
    }

    public function test_a_junk_band_default_is_no_limit_not_a_guess(): void
    {
        $user = $this->userWithSettings(['browseReachMaxDistance' => 'nonsense']);
        $this->assertSame(
            (float) DistancePreferenceFilter::DISTANCE_UNLIMITED,
            $this->filter()->maxDistanceMiles($user)
        );
    }

    public function test_the_band_default_is_not_an_outbound_cap(): void
    {
        // authorMaxDistanceMiles must read ONLY the member's own choices.
        $user = $this->userWithSettings(['browseReachMaxDistance' => 4.8]);
        $this->assertSame(
            (float) DistancePreferenceFilter::DISTANCE_UNLIMITED,
            $this->filter()->authorMaxDistanceMiles($user)
        );
    }

    /*
     * The inbound/outbound split. myPostsMaxDistance is the member's own answer to "how far away
     * can people see my posts"; browseMaxDistance is the fallback, so a member who has never
     * separated the two keeps exactly the behaviour they had before the split.
     *
     * These cases must stay in step with iznik-server-go/utils/reachcap.go's authorCapMiles, which
     * resolves the same two keys in SQL. TestAuthorReachCapResolution in
     * iznik-server-go/test/authorreachcap_test.go is the same table.
     */

    public function test_outbound_falls_back_to_the_browse_choice_when_not_split(): void
    {
        // The linked case, and the whole point of the fallback: unchanged behaviour.
        $user = $this->userWithSettings(['browseMaxDistance' => 10.0]);
        $this->assertSame(10.0, $this->filter()->authorMaxDistanceMiles($user));
    }

    public function test_outbound_own_choice_wins_over_the_browse_choice(): void
    {
        $user = $this->userWithSettings([
            'browseMaxDistance' => 10.0,
            'myPostsMaxDistance' => 30.0,
        ]);
        $this->assertSame(30.0, $this->filter()->authorMaxDistanceMiles($user));
    }

    public function test_outbound_own_choice_wins_when_narrower_too(): void
    {
        // Narrower is just as much a choice as wider - it must not be second-guessed.
        $user = $this->userWithSettings([
            'browseMaxDistance' => 30.0,
            'myPostsMaxDistance' => 5.0,
        ]);
        $this->assertSame(5.0, $this->filter()->authorMaxDistanceMiles($user));
    }

    public function test_outbound_sentinel_means_no_limit_and_does_not_fall_back(): void
    {
        // The top stop on the outbound slider. It must NOT fall through to the narrower
        // browse choice, or "no limit" would silently mean "10 miles".
        $user = $this->userWithSettings([
            'browseMaxDistance' => 10.0,
            'myPostsMaxDistance' => DistancePreferenceFilter::DISTANCE_UNLIMITED,
        ]);
        $this->assertSame(
            (float) DistancePreferenceFilter::DISTANCE_UNLIMITED,
            $this->filter()->authorMaxDistanceMiles($user)
        );
    }

    public function test_outbound_null_reads_as_unset_and_falls_back(): void
    {
        // Re-linking patches both outbound keys to null, and PATCH /session stores them AS JSON
        // null (it replaces the blob wholesale). So this is the normal re-linked row, not an edge
        // case, and it must read as unset.
        $user = $this->userWithSettings([
            'browseMaxDistance' => 10.0,
            'myPostsMaxDistance' => null,
        ]);
        $this->assertSame(10.0, $this->filter()->authorMaxDistanceMiles($user));
    }

    public function test_outbound_non_positive_falls_back_rather_than_meaning_no_limit(): void
    {
        // A 0 or negative radius can only be a derivation artefact: it cannot mean "show my
        // posts to nobody" and it cannot mean "no limit", so the key is ignored. Deliberately
        // different from the inbound maxDistanceMiles, which has a different fallback - see the
        // method docblock.
        foreach ([0, -5.0] as $bad) {
            $user = $this->userWithSettings([
                'browseMaxDistance' => 10.0,
                'myPostsMaxDistance' => $bad,
            ]);
            $this->assertSame(
                10.0,
                $this->filter()->authorMaxDistanceMiles($user),
                'outbound ' . var_export($bad, true) . ' should fall back'
            );
        }
    }

    public function test_outbound_with_neither_key_is_unlimited(): void
    {
        $user = $this->userWithSettings(['browseReachMaxDistance' => 4.8]);
        $this->assertSame(
            (float) DistancePreferenceFilter::DISTANCE_UNLIMITED,
            $this->filter()->authorMaxDistanceMiles($user)
        );
    }

    public function test_outbound_reads_a_json_string_settings_blob(): void
    {
        $user = new User();
        $user->settings = json_encode(['myPostsMaxDistance' => 22.5]);
        $this->assertSame(22.5, $this->filter()->authorMaxDistanceMiles($user));
    }

    public function test_splitting_outbound_does_not_change_the_inbound_cap(): void
    {
        // The two halves must stay independent: an outbound choice is not an inbound one.
        $user = $this->userWithSettings([
            'browseMaxDistance' => 10.0,
            'myPostsMaxDistance' => 30.0,
        ]);
        $this->assertSame(10.0, $this->filter()->maxDistanceMiles($user));
    }

    public function test_an_outbound_only_choice_leaves_inbound_on_the_band_default(): void
    {
        // A member who has never narrowed what they see, but has narrowed who sees them.
        $user = $this->userWithSettings([
            'browseReachMaxDistance' => 4.8,
            'myPostsMaxDistance' => 30.0,
        ]);
        $this->assertSame(4.8, $this->filter()->maxDistanceMiles($user));
        $this->assertSame(30.0, $this->filter()->authorMaxDistanceMiles($user));
    }

    // --- Drive-minutes budget (browseMaxMinutes) — the site's rule, applied to mail ---

    public function test_max_minutes_zero_when_settings_absent_or_invalid(): void
    {
        $this->assertSame(0.0, $this->filter()->maxMinutes($this->userWithSettings(null)));
        $this->assertSame(0.0, $this->filter()->maxMinutes($this->userWithSettings([])));
        $this->assertSame(0.0, $this->filter()->maxMinutes($this->userWithSettings(['browseMaxMinutes' => null])));
        $this->assertSame(0.0, $this->filter()->maxMinutes($this->userWithSettings(['browseMaxMinutes' => 'soon'])));
        $this->assertSame(0.0, $this->filter()->maxMinutes($this->userWithSettings(['browseMaxMinutes' => 0])));
        $this->assertSame(0.0, $this->filter()->maxMinutes($this->userWithSettings(['browseMaxMinutes' => -5])));
    }

    public function test_max_minutes_returns_configured_budget(): void
    {
        $this->assertSame(25.0, $this->filter()->maxMinutes($this->userWithSettings(['browseMaxMinutes' => 25])));
    }

    public function test_max_minutes_ignores_the_band_default_on_purpose(): void
    {
        // Band defaults are MILES (browseReachMaxDistance); there is no minutes
        // band default, matching apiv2 resolveMaxMinutes.
        $user = $this->userWithSettings(['browseReachMaxDistance' => 4.8]);
        $this->assertSame(0.0, $this->filter()->maxMinutes($user));
    }

    public function test_inbound_minutes_decide_in_both_directions_when_answered(): void
    {
        // The stuck-badge case: crow-near (8 <= 12.2) but a 30-minute drive over
        // a 25-minute budget - OUT, where the crow rule alone kept it.
        $this->assertFalse($this->filter()->passesInbound(8.0, 30.0, 12.2, 25.0, false));
        // The rescue case: crow-far (14 > 12.2) but a 20-minute drive - IN.
        $this->assertTrue($this->filter()->passesInbound(14.0, 20.0, 12.2, 25.0, false));
        // Inclusive at the budget, matching the client.
        $this->assertTrue($this->filter()->passesInbound(8.0, 25.0, 12.2, 25.0, false));
    }

    public function test_inbound_falls_back_to_crow_without_an_answer_or_budget(): void
    {
        // No road answer (routing outage / unroutable): the crow rule governs.
        $this->assertTrue($this->filter()->passesInbound(8.0, null, 12.2, 25.0, false));
        $this->assertFalse($this->filter()->passesInbound(14.0, null, 12.2, 25.0, false));
        // No budget: pure crow, the pre-minutes behaviour.
        $this->assertTrue($this->filter()->passesInbound(8.0, 30.0, 12.2, 0.0, false));
    }

    public function test_inbound_unlimited_slider_disables_everything(): void
    {
        // The site applies no checks at all when the miles slider is unlimited,
        // even with a minutes budget stored - mail must match.
        $unlimited = (float) DistancePreferenceFilter::DISTANCE_UNLIMITED;
        $this->assertTrue($this->filter()->passesInbound(500.0, 400.0, $unlimited, 25.0, false));
    }

    public function test_inbound_own_posts_always_pass(): void
    {
        $this->assertTrue($this->filter()->passesInbound(500.0, 400.0, 12.2, 25.0, true));
    }
}
