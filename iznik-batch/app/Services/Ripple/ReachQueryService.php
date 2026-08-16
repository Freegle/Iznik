<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Read-side reach test: "is this location inside a post's current rippled-out
 * reach?" — the single gate shared by browse "Nearby" (#1), reply-eligibility
 * (#2) and held-reply release (#3).
 *
 * Uses the SRID-3857 convention of rippling_reach.polygon / messages_spatial.point
 * (lng/lat degrees under an SRID-3857 label), so points are built the same way the
 * Go API builds them: ST_SRID(POINT(lng, lat), 3857).
 */
class ReachQueryService
{
    private const SRID = 3857;

    /** Memoized: whether the sandwich-bounds columns exist (pre-migration deploys fall back). */
    private static ?bool $boundsColumnsExist = null;

    private function boundsAvailable(): bool
    {
        if (self::$boundsColumnsExist === null) {
            try {
                self::$boundsColumnsExist = Schema::hasColumn('rippling_reach', 'outer_bound');
            } catch (\Throwable) {
                self::$boundsColumnsExist = false;
            }
        }

        return self::$boundsColumnsExist;
    }

    /**
     * Is (lat,lng) inside the post's current reach polygon? False if the post has
     * no reach row yet (not rippling / not in messages_spatial).
     *
     * Consults the sandwich bounds first (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md):
     * outside outer_bound is an authoritative reject, inside inner_bound an authoritative
     * accept, and only the band between them touches the ~178KB exact polygon — always via
     * a correlated EXISTS (lazy BLOB fetch does not cross OR items). Degraded (POINT)
     * bounds are treated as absent: held-reply release serves completed posts, which must
     * resolve against the exact polygon.
     */
    public function isWithinReach(int $msgid, float $lat, float $lng, ?string $band = null): bool
    {
        try {
            if ($this->boundsAvailable()) {
                $point = 'ST_SRID(POINT(?, ?), ' . self::SRID . ')';
                $row = DB::selectOne(
                    "SELECT EXISTS(
                        SELECT 1 FROM rippling_reach rr
                        WHERE rr.msgid = ?
                          AND ((ST_GeometryType(rr.outer_bound) <> 'POINT'
                                AND ST_Contains(rr.outer_bound, $point)
                                AND (COALESCE(ST_Contains(rr.inner_bound, $point), 0) = 1
                                     OR EXISTS (SELECT 1 FROM rippling_reach r2
                                         WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, $point))))
                               OR (ST_GeometryType(rr.outer_bound) = 'POINT'
                                   AND EXISTS (SELECT 1 FROM rippling_reach r2
                                       WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, $point))))
                     ) AS within",
                    [$msgid, $lng, $lat, $lng, $lat, $lng, $lat, $lng, $lat]
                );
            } else {
                $row = DB::selectOne(
                    'SELECT EXISTS(
                        SELECT 1 FROM rippling_reach
                        WHERE msgid = ?
                          AND ST_Contains(polygon, ST_SRID(POINT(?, ?), ' . self::SRID . ')) = 1
                     ) AS within',
                    [$msgid, $lng, $lat]
                );
            }

            if ((bool) ($row->within ?? 0)) {
                return true;
            }

            // Only now, when the reach proper has said no: a post that already covers this
            // viewer never pays for the ring test.
            return $this->isWithinOverflow($msgid, $lat, $lng, $band);
        } catch (\Throwable $e) {
            // rippling_reach is created by the reach engine (PR A). Until that is
            // deployed the table may be absent — fail open ("not within reach") so
            // callers degrade safely instead of throwing.
            return false;
        }
    }

    /**
     * Is the post reply-eligible for a viewer who may have several locations? True
     * if ANY of the viewer's (lat,lng) points falls inside the reach — viewers can
     * define multiple browse locations and a post qualifies if any one is covered.
     *
     * @param array<int,array{0:float,1:float}> $latLngPairs list of [lat, lng]
     */
    public function isWithinReachAny(int $msgid, array $latLngPairs, ?string $band = null): bool
    {
        foreach ($latLngPairs as $pair) {
            if (!isset($pair[0], $pair[1])) {
                continue;
            }
            if ($this->isWithinReach($msgid, (float) $pair[0], (float) $pair[1], $band)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which ring, if any, this person could be let in by - the same question the mail and the
     * browse feed ask, answered the same way, because a person let in by one and not the others
     * is shown a post they cannot reply to, or told about one they cannot see.
     *
     * Rural: the ring for their own area type. Fairness: the ring for their deprivation fifth,
     * which is not in this database and is asked of the spatial server.
     *
     * Returns null when nothing applies, which includes having no area type recorded. Absent
     * has to mean "not eligible" rather than "matches anything", or switching a lane on would
     * widen things for everyone at once.
     *
     * Both are looked UP from a fixed set, never built by pasting a value into a path.
     */
    private function overflowPath(float $lat, float $lng, ?string $band): ?string
    {
        if (config('freegle.ripple.rural_access.enabled', false) && $band !== null && $band !== '') {
            $paths = ['dense' => '$.rural.dense', 'medium' => '$.rural.medium', 'sparse' => '$.rural.sparse'];
            if (isset($paths[$band])) {
                return $paths[$band];
            }
        }

        if (config('freegle.ripple.fairness.enabled', false)) {
            $q = $this->quintileFor($lat, $lng);
            $max = max(1, min(4, (int) config('freegle.ripple.fairness.max_quintile', 1)));
            if ($q !== null && $q >= 1 && $q <= $max) {
                // Quoted: a JSON path member that is a number is not a bare identifier, so
                // $.fairness.1 does not address the ring - it silently matches nothing.
                return '$.fairness."' . $q . '"';
            }
        }

        return null;
    }

    /**
     * This point's deprivation fifth, from the spatial server, or null if it cannot say.
     *
     * Asked rather than stored: the spatial server already holds the index for the isochrone,
     * so nothing else has to keep deprivation data and nothing records what fifth a person is
     * in. Null on any failure, so an outage costs the lane its extra people rather than
     * admitting everyone inside a stretched ring - which is what the fifth exists to prevent.
     */
    private function quintileFor(float $lat, float $lng): ?int
    {
        try {
            $base = rtrim((string) config('freegle.routing_server_url'), '/');
            $r = Http::timeout(5)->get($base . '/v1/quintile', ['lat' => $lat, 'lng' => $lng]);
            if (!$r->successful() || !$r->json('available')) {
                return null;
            }
            $q = (int) $r->json('quintile');

            return $q >= 1 && $q <= 5 ? $q : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Is this point inside a ring that would let them in?
     *
     * Only consulted when the reach proper has said no, so a post that already covers this
     * person costs nothing extra.
     */
    private function isWithinOverflow(int $msgid, float $lat, float $lng, ?string $band): bool
    {
        $path = $this->overflowPath($lat, $lng, $band);
        if ($path === null) {
            return false;
        }

        try {
            $row = DB::selectOne(
                'SELECT EXISTS(
                    SELECT 1 FROM rippling_reach
                    WHERE msgid = ?
                      AND overflow_bounds IS NOT NULL
                      AND ST_Contains(
                            ST_GeomFromText(JSON_UNQUOTE(JSON_EXTRACT(overflow_bounds, ?)), ' . self::SRID . '),
                            ST_SRID(POINT(?, ?), ' . self::SRID . '))
                 ) AS within',
                [$msgid, $path, $lng, $lat]
            );

            return (bool) ($row->within ?? 0);
        } catch (\Throwable $e) {
            // Same posture as isWithinReach: an unreadable ring means "not within", never an
            // exception into a reply flow.
            return false;
        }
    }
}
