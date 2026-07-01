<?php

namespace App\Services\Ripple;

use App\Models\User;

/**
 * Filters member-facing post emails by the member's `settings.browseMaxDistance`
 * preference (miles) — the same preference driving the Nearby browse distance
 * slider. See the design doc:
 * docs/superpowers/specs/2026-07-01-distance-preference-email-filtering-design.md
 * (and its companion, the Nearby browse relevance/slider design).
 *
 * This is a pure, dependency-free, narrowing step layered strictly AFTER the
 * existing group-membership / rippling-reach selection in all three
 * member-notification pipelines in UnifiedDigestService:
 *   - the daily digest (sendDigestToUser, after scoreAndSortAvailable),
 *   - the immediate cursor path (processGroupImmediate),
 *   - the immediate reach-mail path (mailNewlyReachedForPost).
 * It never adds candidates the existing selection wouldn't already allow.
 *
 * Distance basis: REAL (unblurred) haversine distance, not the blurred
 * distance the browse feed displays — see the design doc's "Distance basis
 * decision" for the reasoning (the digest email already shows a real
 * distance, so filtering on the same basis keeps the email internally
 * consistent; a filter is a binary yes/no signal, not a displayed precise
 * figure, so the blur privacy rationale doesn't transfer here).
 *
 * Mirrors the existing DigestPostScorer pattern: small, container-
 * instantiated, unit-testable in isolation with no DB/I/O.
 */
class DistancePreferenceFilter
{
    /**
     * Sentinel for "no limit" on settings.browseMaxDistance. Must stay
     * byte-identical across all three codebases that share this concept:
     * iznik-nuxt3/constants.js BROWSE_DISTANCE_UNLIMITED,
     * iznik-server-go/isochrone/message.go BrowseDistanceUnlimited, and this
     * constant. Equal to Number.MAX_SAFE_INTEGER.
     */
    public const DISTANCE_UNLIMITED = 9007199254740991;

    /**
     * Resolve a member's configured max distance in miles from
     * settings.browseMaxDistance.
     *
     * Absent, null, non-numeric, <= 0, or >= the sentinel all mean "no
     * limit" and collapse to DISTANCE_UNLIMITED so callers can short-circuit
     * with a single comparison — this is the fast path that the overwhelming
     * majority of members (who have never touched the slider) take.
     */
    public function maxDistanceMiles(User $user): float
    {
        $settings = $user->settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }

        $value = is_array($settings) ? ($settings['browseMaxDistance'] ?? null) : null;

        if (!is_numeric($value)) {
            return (float) self::DISTANCE_UNLIMITED;
        }

        $value = (float) $value;
        if ($value <= 0 || $value >= self::DISTANCE_UNLIMITED) {
            return (float) self::DISTANCE_UNLIMITED;
        }

        return $value;
    }

    /**
     * Whether a candidate post at $distanceMiles from the recipient passes
     * their distance preference.
     *
     * Own posts and the unlimited sentinel always pass, regardless of
     * distance (own posts: matches the parent browse design's "own posts
     * always included" and the existing tested behaviour that posters get
     * mailed their own posts — see test_immediate_includes_poster_own_post
     * and test_digest_includes_users_own_posts). The boundary is inclusive
     * (<=, not <) so a post exactly at the configured limit is kept.
     */
    public function passes(float $distanceMiles, float $maxDistanceMiles, bool $isOwnPost): bool
    {
        return $isOwnPost
            || $maxDistanceMiles >= self::DISTANCE_UNLIMITED
            || $distanceMiles <= $maxDistanceMiles;
    }

    /**
     * Great-circle (haversine) distance in MILES between two (lat,lng)
     * points in degrees. The canonical distance calculation used by all
     * three insertion points, consolidating what would otherwise be a third
     * near-duplicate of UnifiedDigestService::haversineMetres (metres) and
     * UnifiedDigest::haversineDistance (miles) — see the design doc's
     * "Distance precedent already in the codebase".
     */
    public function distanceMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMiles = 3959.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusMiles * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
