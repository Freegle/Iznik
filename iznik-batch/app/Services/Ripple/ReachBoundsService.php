<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sandwich bounds for rippling_reach.polygon (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
 *
 * The exact reach polygons are grid-fill isochrones averaging ~11k vertices / 178 KB, and
 * the reach browse/count/digest queries were paying a full BLOB fetch + point-in-polygon
 * test for every spatial-index candidate. This service maintains two SMALL derived
 * polygons per reach in the sibling table rippling_reach_bounds:
 *
 *   outer_bound ⊇ polygon  — viewer outside it is DEFINITELY out of reach (cheap reject)
 *   inner_bound ⊆ polygon  — viewer inside it is DEFINITELY in reach (cheap accept);
 *                            NULL simply disables the cheap accept.
 *
 * Readers only touch the exact polygon for the thin band between the two. The exact
 * polygon stays authoritative throughout: bounds are verified at write time
 * (ST_Contains(outer_bound, polygon) AND ST_Contains(polygon, inner_bound)) and anything
 * that cannot be verified falls back — outer to ST_Envelope (always safe, just loose),
 * inner to NULL. ~94% of production polygons are technically invalid geometry that can
 * make GIS functions THROW, so every step is wrapped: a failed derivation must never
 * abort the calling expander tick.
 *
 * TOLERANCE is in DEGREES: the polygons are lng/lat degree coordinates stored under an
 * SRID-3857 label (see ExpandService), which MySQL treats as Cartesian. 0.002° was
 * validated against production polygons (0 sandwich violations across ~1,455 point
 * trials incl. Thames estuary cases) with a boundary band of ~19% of candidates.
 */
class ReachBoundsService
{
    public const TOLERANCE = 0.002;

    /** Cached table-existence check so a pre-migration deploy degrades to a no-op. */
    private static ?bool $tableExists = null;

    private function ready(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable('rippling_reach_bounds');
            } catch (\Throwable) {
                self::$tableExists = false;
            }
        }

        return self::$tableExists;
    }

    /**
     * Sync the bounds row for a post after a polygon write: prefer bounds the routing
     * server derived on its own rasterisation grid (shipped with the tick's catchment —
     * always valid geometry, superset/subset by construction), fall back to deriving
     * from the stored polygon in SQL. Provided bounds are verified against the stored
     * polygon exactly like derived ones — they bound the raw tick isochrone, while the
     * stored polygon may have been unioned with the origin group's area and clipped by
     * rejections, so verbatim trust would be wrong.
     */
    public function sync(int $msgid, ?string $outerWkt = null, ?string $innerWkt = null): void
    {
        if ($outerWkt === null) {
            $this->syncFromPolygon($msgid);

            return;
        }
        if (!$this->ready()) {
            return;
        }
        $rows = DB::select('SELECT 1 AS x FROM rippling_reach WHERE msgid = ?', [$msgid], false);
        if (empty($rows)) {
            return;
        }

        $stored = false;
        try {
            DB::statement(
                'INSERT INTO rippling_reach_bounds (msgid, outer_bound, inner_bound)
                 SELECT rr.msgid, ST_GeomFromText(?, 3857), '
                    . ($innerWkt !== null ? 'ST_GeomFromText(?, 3857)' : 'NULL') . '
                   FROM rippling_reach rr
                  WHERE rr.msgid = ?
                 ON DUPLICATE KEY UPDATE
                    outer_bound = VALUES(outer_bound),
                    inner_bound = VALUES(inner_bound)',
                $innerWkt !== null ? [$outerWkt, $innerWkt, $msgid] : [$outerWkt, $msgid]
            );
            $stored = true;
        } catch (\Throwable) {
            // Unusable provided geometry — derive from the polygon instead.
        }
        if (!$stored) {
            $this->syncFromPolygon($msgid);

            return;
        }

        [$outerOk, $innerOk] = $this->verifySandwich($msgid);
        if ($outerOk !== 1) {
            // The provided outer bounds the raw tick isochrone; the STORED polygon may
            // additionally include the origin group's area (unionWithOriginGroupArea).
            // Union that area into the outer and re-verify before falling back.
            $this->unionOuterWithOriginGroup($msgid);
            [$outerOk, $innerOk] = $this->verifySandwich($msgid);
        }
        if ($outerOk !== 1) {
            $this->fallbackToEnvelope($msgid);

            return;
        }
        if ($innerOk !== 1) {
            // A clip may have removed area the provided inner still covers: drop the
            // cheap accept, keep the verified outer.
            $this->nullInner($msgid);
        }
    }

    /**
     * Derive (or re-derive) the bounds for a post from its FINAL stored polygon — call
     * after every rippling_reach.polygon write, AFTER any rejection clips have been
     * re-applied. Pure SQL: no routing call, safe for reopened posts too.
     */
    public function syncFromPolygon(int $msgid): void
    {
        if (!$this->ready()) {
            return;
        }

        // No reach row → nothing to bound (any stale bounds row would already have been
        // removed by the FK cascade). useReadPdo=false: we may be called immediately
        // after the reach row was written, and Galera apply on a read node can lag the
        // certified commit — this check and the verification below must see our own
        // writes, so both stay on the write connection.
        $rows = DB::select('SELECT 1 AS x FROM rippling_reach WHERE msgid = ?', [$msgid], false);
        if (empty($rows)) {
            return;
        }

        // Preferred derivation: simplify then buffer outward/inward by the same tolerance,
        // so the simplification error can never poke through the buffer. NB: success is
        // "did not throw" — INSERT..ON DUPLICATE reports 0 affected rows when the derived
        // values are unchanged, so the row count cannot distinguish no-op from failure.
        $derived = false;
        try {
            DB::statement(
                'INSERT INTO rippling_reach_bounds (msgid, outer_bound, inner_bound)
                 SELECT rr.msgid,
                        ST_Buffer(ST_Simplify(rr.polygon, ?), ?),
                        ST_Buffer(ST_Simplify(rr.polygon, ?), ?)
                   FROM rippling_reach rr
                  WHERE rr.msgid = ?
                 ON DUPLICATE KEY UPDATE
                    outer_bound = VALUES(outer_bound),
                    inner_bound = VALUES(inner_bound)',
                [self::TOLERANCE, self::TOLERANCE, self::TOLERANCE, -self::TOLERANCE, $msgid]
            );
            $derived = true;
        } catch (\Throwable) {
            // Invalid stored geometry — fall through to the envelope fallback.
        }

        if (!$derived) {
            $this->fallbackToEnvelope($msgid);

            return;
        }

        // Write-time verification: the sandwich is only safe if outer ⊇ polygon ⊇ inner.
        // Errors count as failures (invalid geometry ⇒ fallbacks), per the design doc.
        [$outerOk, $innerOk] = $this->verifySandwich($msgid);

        if ($outerOk !== 1) {
            $this->fallbackToEnvelope($msgid);
        } elseif ($innerOk !== 1) {
            // Outer verified but inner didn't (e.g. negative buffer artefacts on a
            // near-degenerate polygon): keep the good outer, drop the cheap accept.
            $this->nullInner($msgid);
        }
    }

    /**
     * A Taken/Received post has left the browsable candidate set: collapse its OUTER
     * bound to a degenerate point (the reach origin) and clear the inner bound, so the
     * cheap path stops matching it. The exact polygon is deliberately untouched — the
     * digest's "came and went" section, held replies to taken posts and un-completion
     * all still read it (pruning rippling_reach itself was verified UNSAFE).
     */
    public function degradeForCompleted(int $msgid): void
    {
        if (!$this->ready()) {
            return;
        }

        try {
            DB::update(
                'UPDATE rippling_reach_bounds b
                   JOIN rippling_reach rr ON rr.msgid = b.msgid
                    SET b.outer_bound = ST_SRID(POINT(rr.lng, rr.lat), 3857),
                        b.inner_bound = NULL
                  WHERE b.msgid = ?',
                [$msgid]
            );
        } catch (\Throwable $e) {
            Log::warning("ripple: bounds degrade failed for msg {$msgid}: {$e->getMessage()}");
        }
    }

    /**
     * Verify the stored sandwich against the stored polygon.
     * useReadPdo=false throughout: this always runs immediately after our own write.
     *
     * @return array{0:int,1:int} [outer ok, inner ok] — errors count as failures.
     */
    private function verifySandwich(int $msgid): array
    {
        try {
            $check = DB::select(
                'SELECT ST_Contains(b.outer_bound, rr.polygon) AS o,
                        (b.inner_bound IS NULL OR ST_Contains(rr.polygon, b.inner_bound)) AS i
                   FROM rippling_reach_bounds b
                   JOIN rippling_reach rr ON rr.msgid = b.msgid
                  WHERE b.msgid = ?',
                [$msgid],
                false
            )[0] ?? null;

            return [(int) ($check->o ?? 0), (int) ($check->i ?? 0)];
        } catch (\Throwable) {
            return [0, 0];
        }
    }

    /**
     * Union the post's origin-group area into its outer bound — the repair step for
     * provided bounds when the stored polygon was widened by unionWithOriginGroupArea.
     * Best-effort: verification afterwards decides the outcome either way.
     */
    private function unionOuterWithOriginGroup(int $msgid): void
    {
        try {
            DB::update(
                'UPDATE rippling_reach_bounds b
                    SET b.outer_bound = COALESCE(
                        (SELECT ST_Union(b.outer_bound, g.polyindex)
                           FROM messages_groups mg
                           JOIN `groups` g ON g.id = mg.groupid
                          WHERE mg.msgid = b.msgid AND mg.deleted = 0
                            AND g.polyindex IS NOT NULL
                            AND ST_GeometryType(g.polyindex) <> \'POINT\'
                          ORDER BY mg.arrival ASC
                          LIMIT 1),
                        b.outer_bound)
                  WHERE b.msgid = ?',
                [$msgid]
            );
        } catch (\Throwable) {
            // Leave the stored outer as-is; verification decides.
        }
    }

    /** Envelope outer (always a superset, MBR-only so it works on invalid geometry) + no inner. */
    private function fallbackToEnvelope(int $msgid): void
    {
        try {
            DB::update(
                'INSERT INTO rippling_reach_bounds (msgid, outer_bound, inner_bound)
                 SELECT rr.msgid, ST_Envelope(rr.polygon), NULL
                   FROM rippling_reach rr
                  WHERE rr.msgid = ?
                 ON DUPLICATE KEY UPDATE
                    outer_bound = VALUES(outer_bound),
                    inner_bound = NULL',
                [$msgid]
            );
        } catch (\Throwable $e) {
            // Even the envelope failed: remove any stale bounds row so readers use the
            // exact polygon (a missing row is the readers' fail-safe path).
            try {
                DB::delete('DELETE FROM rippling_reach_bounds WHERE msgid = ?', [$msgid]);
            } catch (\Throwable) {
                // Nothing more we can safely do.
            }
            Log::warning("ripple: bounds derivation failed for msg {$msgid}: {$e->getMessage()}");
        }
    }

    private function nullInner(int $msgid): void
    {
        try {
            DB::update('UPDATE rippling_reach_bounds SET inner_bound = NULL WHERE msgid = ?', [$msgid]);
        } catch (\Throwable $e) {
            Log::warning("ripple: bounds inner-null failed for msg {$msgid}: {$e->getMessage()}");
        }
    }
}
