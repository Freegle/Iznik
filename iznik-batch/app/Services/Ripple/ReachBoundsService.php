<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sandwich bounds for rippling_reach.polygon, stored as same-row columns
 * (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md):
 *
 *   outer_bound ⊇ polygon (NOT NULL, spatially indexed — the R-tree browse drives)
 *   inner_bound ⊆ polygon, or NULL (no cheap accept)
 *
 * Sentinel ladder for outer_bound, which the readers rely on:
 *   real derived/provided bound  — cheap reject/accept work
 *   ST_Envelope(polygon)         — derivation failed: MBR still finds the row, the
 *                                  exact polygon decides (correct, just less pruning)
 *   degenerate POINT             — completed posts ONLY: pruned from the R-tree itself
 *
 * Same-row storage means the expander writes bounds IN THE SAME STATEMENT as the
 * polygon (no cross-row/table timing window). This service holds the shared pieces:
 * the derivation SQL fragments, write-time verification for routing-provided bounds,
 * the completion degrade/restore hooks, and the fallback ladder. ~94% of production
 * polygons are technically invalid geometry that can make GIS functions THROW, so
 * every step is wrapped: a failed derivation must never abort the calling tick.
 *
 * TOLERANCE is in DEGREES: the polygons are lng/lat degree coordinates stored under an
 * SRID-3857 label (see ExpandService), which MySQL treats as Cartesian. 0.002° was
 * validated against production polygons (0 sandwich violations across ~1,455 point
 * trials incl. Thames estuary cases) with a boundary band of ~19% of candidates.
 */
class ReachBoundsService
{
    public const TOLERANCE = 0.002;

    /** Cached column-existence check so a pre-migration deploy degrades to a no-op. */
    private static ?bool $columnsExist = null;

    /**
     * SQL expression deriving the outer bound from a polygon expression, for embedding
     * in the same statement that writes the polygon.
     */
    public static function outerExpr(string $polyExpr): string
    {
        return "ST_Buffer(ST_Simplify($polyExpr, " . self::TOLERANCE . '), ' . self::TOLERANCE . ')';
    }

    /** As outerExpr, for the inner bound (negative buffer). */
    public static function innerExpr(string $polyExpr): string
    {
        return "ST_Buffer(ST_Simplify($polyExpr, " . self::TOLERANCE . '), -' . self::TOLERANCE . ')';
    }

    public function ready(): bool
    {
        if (self::$columnsExist === null) {
            try {
                self::$columnsExist = Schema::hasColumn('rippling_reach', 'outer_bound');
            } catch (\Throwable) {
                self::$columnsExist = false;
            }
        }

        return self::$columnsExist;
    }

    /**
     * Set the bounds for a post whose polygon was JUST written without inline bounds
     * (or needs them re-verified): prefer bounds the routing server derived on its own
     * rasterisation grid, fall back to deriving from the stored polygon. Provided
     * bounds are verified against the stored polygon — they bound the raw tick
     * isochrone, while the stored polygon may have been unioned with the origin
     * group's area and clipped by rejections, so verbatim trust would be wrong.
     */
    public function sync(int $msgid, ?string $outerWkt = null, ?string $innerWkt = null): void
    {
        if (!$this->ready()) {
            return;
        }
        if ($outerWkt === null) {
            $this->syncFromPolygon($msgid);

            return;
        }

        $stored = false;
        try {
            DB::update(
                'UPDATE rippling_reach
                    SET outer_bound = ST_GeomFromText(?, 3857), '
                    . ($innerWkt !== null ? 'inner_bound = ST_GeomFromText(?, 3857), ' : 'inner_bound = NULL, ') .
                    'updated_at = updated_at
                  WHERE msgid = ?',
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
     * Derive (or re-derive) the bounds from the FINAL stored polygon — used for
     * reopened posts and as the fallback when no routing bounds exist. Pure SQL.
     */
    public function syncFromPolygon(int $msgid): void
    {
        if (!$this->ready()) {
            return;
        }

        $derived = false;
        try {
            DB::update(
                'UPDATE rippling_reach
                    SET outer_bound = ' . self::outerExpr('polygon') . ',
                        inner_bound = ' . self::innerExpr('polygon') . ',
                        updated_at = updated_at
                  WHERE msgid = ?',
                [$msgid]
            );
            $derived = true;
        } catch (\Throwable) {
            // Invalid stored geometry — fall through to the envelope fallback.
        }

        if (!$derived) {
            $this->fallbackToEnvelope($msgid);

            return;
        }

        // Derived-from-polygon bounds are supersets/subsets by buffer construction, but
        // verify anyway — GIS edge cases on invalid geometry are exactly what the
        // fallback ladder exists for. Errors count as failures.
        [$outerOk, $innerOk] = $this->verifySandwich($msgid);
        if ($outerOk !== 1) {
            $this->fallbackToEnvelope($msgid);
        } elseif ($innerOk !== 1) {
            $this->nullInner($msgid);
        }
    }

    /**
     * A Taken/Received post has left the browsable candidate set: collapse its OUTER
     * bound to a degenerate point (the reach origin) and clear the inner bound. With
     * the browse R-tree driven from outer_bound, this prunes the post from the index
     * itself. The exact polygon is deliberately untouched — the digest's "came and
     * went" section, held replies to taken posts and un-completion all still read it.
     */
    public function degradeForCompleted(int $msgid): void
    {
        if (!$this->ready()) {
            return;
        }

        try {
            DB::update(
                'UPDATE rippling_reach
                    SET outer_bound = ST_SRID(POINT(lng, lat), 3857),
                        inner_bound = NULL,
                        updated_at = updated_at
                  WHERE msgid = ?',
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
                'SELECT ST_Contains(outer_bound, polygon) AS o,
                        (inner_bound IS NULL OR ST_Contains(polygon, inner_bound)) AS i
                   FROM rippling_reach
                  WHERE msgid = ?',
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
                'UPDATE rippling_reach rr
                    SET rr.outer_bound = COALESCE(
                        (SELECT ST_Union(rr.outer_bound, g.polyindex)
                           FROM messages_groups mg
                           JOIN `groups` g ON g.id = mg.groupid
                          WHERE mg.msgid = rr.msgid AND mg.deleted = 0
                            AND g.polyindex IS NOT NULL
                            AND ST_GeometryType(g.polyindex) <> \'POINT\'
                          ORDER BY mg.arrival ASC
                          LIMIT 1),
                        rr.outer_bound),
                        rr.updated_at = rr.updated_at
                  WHERE rr.msgid = ?',
                [$msgid]
            );
        } catch (\Throwable) {
            // Leave the stored outer as-is; verification decides.
        }
    }

    /**
     * Envelope outer (always a superset — MBR-only, works on invalid geometry) + no
     * inner. The MBR still finds the row, so the post stays visible via the exact test.
     */
    private function fallbackToEnvelope(int $msgid): void
    {
        try {
            DB::update(
                'UPDATE rippling_reach
                    SET outer_bound = ST_Envelope(polygon), inner_bound = NULL,
                        updated_at = updated_at
                  WHERE msgid = ?',
                [$msgid]
            );
        } catch (\Throwable $e) {
            // Even the envelope failed. The column keeps its previous value: a stale
            // outer is safe-loose, but a stale INNER could cheap-accept a clipped-out
            // area, so clear it if we possibly can.
            $this->nullInner($msgid);
            Log::warning("ripple: bounds derivation failed for msg {$msgid}: {$e->getMessage()}");
        }
    }

    private function nullInner(int $msgid): void
    {
        try {
            DB::update(
                'UPDATE rippling_reach SET inner_bound = NULL, updated_at = updated_at WHERE msgid = ?',
                [$msgid]
            );
        } catch (\Throwable $e) {
            Log::warning("ripple: bounds inner-null failed for msg {$msgid}: {$e->getMessage()}");
        }
    }
}
