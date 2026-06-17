<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * Read-side reach test: "is this location inside a post's current rippled-out
 * reach?" — the single gate shared by browse "Nearby" (#1), reply-eligibility
 * (#2) and held-reply release (#3).
 *
 * Uses the SRID-3857 convention of messages_reach.polygon / messages_spatial.point
 * (lng/lat degrees under an SRID-3857 label), so points are built the same way the
 * Go API builds them: ST_SRID(POINT(lng, lat), 3857).
 */
class ReachQueryService
{
    private const SRID = 3857;

    /**
     * Is (lat,lng) inside the post's current reach polygon? False if the post has
     * no reach row yet (not rippling / not in messages_spatial).
     */
    public function isWithinReach(int $msgid, float $lat, float $lng): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT EXISTS(
                    SELECT 1 FROM messages_reach
                    WHERE msgid = ?
                      AND ST_Contains(polygon, ST_SRID(POINT(?, ?), ' . self::SRID . ')) = 1
                 ) AS within',
                [$msgid, $lng, $lat]
            );

            return (bool) ($row->within ?? 0);
        } catch (\Throwable $e) {
            // messages_reach is created by the reach engine (PR A). Until that is
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
    public function isWithinReachAny(int $msgid, array $latLngPairs): bool
    {
        foreach ($latLngPairs as $pair) {
            if (!isset($pair[0], $pair[1])) {
                continue;
            }
            if ($this->isWithinReach($msgid, (float) $pair[0], (float) $pair[1])) {
                return true;
            }
        }

        return false;
    }
}
