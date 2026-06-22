<?php

namespace App\Services\Ripple;

/**
 * Mirrors iznik-routing-go/digest_simulator.go::scoreDigestPost — the scoring
 * used by the /rippling page's "Digest preview" (inbound) mode — so the unified
 * digest email orders posts the same way moderators see on that page.
 *
 * PERFORMANCE APPROXIMATION: the reference `close` term is 1 - driveMin/maxMinutes,
 * where driveMin comes from a full Dijkstra drive-time isochrone computed per
 * recipient in the routing server. The unified digest is mass mail (potentially
 * millions of recipient-sends per run), so running an isochrone per recipient is
 * infeasible inline. Instead we approximate drive-time with the straight-line
 * (haversine) distance from the recipient to the post origin, normalised by the
 * post's reach radius (or a fixed default for posts with no reach row). This is a
 * deliberate trade of fidelity for throughput; see the design spec
 * docs/superpowers/specs/2026-06-22-digest-rippling-score-ordering-design.md.
 *
 * This class is intentionally pure (no DB / no I/O) so it is unit-testable in
 * isolation against the Go reference values.
 */
class DigestPostScorer
{
    /**
     * @param float $distanceMetres Haversine distance recipient -> post origin (drive-time proxy).
     * @param float $reachRadius    Post reach extent in metres (closeness denominator).
     * @param float $ageH           Post age in hours.
     * @param int   $views          messages_likes 'View' count (SUM of count).
     * @param int   $replies        'Interested' chat replies.
     * @param bool  $homeGroup      Post is from the recipient's home group.
     * @param array{close:float,fresh:float,budget:float,anchor:float} $weights
     * @param array{window_hours:float,budget_decay:float} $env
     * @return array{close:float,fresh:float,budget:float,anchor:float,total:float}
     */
    public function score(
        float $distanceMetres,
        float $reachRadius,
        float $ageH,
        int $views,
        int $replies,
        bool $homeGroup,
        array $weights,
        array $env
    ): array {
        // close = 1 - dist/reach, clamped to [0,1]. reach<=0 => no closeness signal.
        $close = 0.0;
        if ($reachRadius > 0) {
            $close = 1.0 - $distanceMetres / $reachRadius;
            if ($close < 0) {
                $close = 0.0;
            }
        }

        $fresh = 1.0 - $ageH / $env['window_hours'];
        if ($fresh < 0) {
            $fresh = 0.0;
        }

        // engagement_rate = (views + 3*replies) / max(ageH, 1); budgetDecay/12
        // converts the minute-equivalent knob to the rate-scale exp() expects.
        $rateAgeH = $ageH < 1 ? 1.0 : $ageH;
        $engagement = ($views + 3 * $replies) / $rateAgeH;
        $budget = exp(-$engagement / ($env['budget_decay'] / 12.0));

        $anchor = $homeGroup ? 1.0 : 0.0;

        $total = $weights['close'] * $close
            + $weights['fresh'] * $fresh
            + $weights['budget'] * $budget
            + $weights['anchor'] * $anchor;

        return [
            'close' => $close,
            'fresh' => $fresh,
            'budget' => $budget,
            'anchor' => $anchor,
            'total' => $total,
        ];
    }
}
