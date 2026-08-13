<?php

namespace App\Services\Ripple;

use App\Database\Expressions\Comparison;
use App\Database\Expressions\Point;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\Value;
use Illuminate\Support\Facades\DB;
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
    public function isWithinReach(int $msgid, float $lat, float $lng): bool
    {
        try {
            // The point built the same way the Go API/every other writer does:
            // ST_SRID(POINT(lng, lat), SRID). $lat/$lng are floats, unambiguous
            // literals under RendersOperands - no Value::of() wrapping needed.
            $point = new StSrid(new Point($lng, $lat), self::SRID);

            if ($this->boundsAvailable()) {
                // Sandwich test: outside outer_bound is an authoritative reject;
                // inside inner_bound (or, absent that, inside the exact polygon via
                // a correlated EXISTS) is an authoritative accept. Degraded (POINT)
                // outer_bound skips straight to the exact-polygon EXISTS - see the
                // class docblock. ->exists() compiles to the same
                // "SELECT EXISTS(SELECT ... WHERE ...)" shape the raw form built by
                // hand, just via the query builder.
                return DB::table('rippling_reach as rr')
                    ->where('rr.msgid', $msgid)
                    ->where(function ($q) use ($point) {
                        $q->where(function ($q2) use ($point) {
                            $q2->where(new Comparison(new StGeometryType('rr.outer_bound'), '<>', Value::of('POINT')))
                                ->where(new StContains('rr.outer_bound', $point))
                                ->where(function ($q3) use ($point) {
                                    // ST_Contains used directly as a boolean predicate is
                                    // equivalent to COALESCE(ST_Contains(...), 0) = 1: MySQL
                                    // treats NULL and 0 identically as "false" in a WHERE.
                                    $q3->where(new StContains('rr.inner_bound', $point))
                                        ->orWhere(function ($q4) use ($point) {
                                            $q4->whereExists(function ($sub) use ($point) {
                                                $sub->from('rippling_reach as r2')
                                                    ->whereColumn('r2.msgid', 'rr.msgid')
                                                    ->where(new StContains('r2.polygon', $point));
                                            });
                                        });
                                });
                        })->orWhere(function ($q2) use ($point) {
                            $q2->where(new Comparison(new StGeometryType('rr.outer_bound'), '=', Value::of('POINT')))
                                ->whereExists(function ($sub) use ($point) {
                                    $sub->from('rippling_reach as r2')
                                        ->whereColumn('r2.msgid', 'rr.msgid')
                                        ->where(new StContains('r2.polygon', $point));
                                });
                        });
                    })
                    ->exists();
            }

            // Pre-migration fallback: no sandwich columns yet, test the exact
            // polygon directly. ST_Contains(...) used directly as the sole where()
            // condition is the truthiness test the raw form's "= 1" spelled out.
            return DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->where(new StContains('polygon', $point))
                ->exists();
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
