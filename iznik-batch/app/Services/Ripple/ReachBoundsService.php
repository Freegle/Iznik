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

    /**
     * The least share of the polygon's area an inner bound may cover and still be worth
     * keeping. Correctness (inner ⊆ polygon) is necessary but not sufficient: the routing
     * grid's 3-cell erosion disintegrates ribbon-shaped rural reaches and ships the
     * largest surviving fragment — a town-core blob covering 1–2% of the polygon. Such an
     * inner verifies, but every viewer between it and the outer bound pays the full
     * ~178KB polygon test, which is what saturated db3 in Aug 2026 (band 58% vs the
     * designed 7–19%). Below this share the SQL derivation from the stored polygon
     * (~90% coverage) is always the better inner. 0.5 rather than something tighter so
     * legitimately-eroded urban inners (solid grids lose only their rim) never churn.
     */
    public const INNER_MIN_AREA_RATIO = 0.5;

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
     *
     * The provided INNER is additionally held to a usefulness bar, not just a
     * correctness one: a verified inner covering a sliver of the polygon (see
     * INNER_MIN_AREA_RATIO) or no inner at all ends as a polygon-derived inner, never
     * as NULL, because NULL sends every in-outer viewer to the full polygon test.
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
            // keep-raw: SET assigns outer_bound/inner_bound from ST_GeomFromText(?, 3857)
            // - a spatial constructor with no builder method - and self-assigns
            // `updated_at = updated_at` to suppress the ON UPDATE auto-bump the delta/
            // shadow copier keys off; ->update(['updated_at' => 'updated_at']) would bind
            // the literal string, and DB::raw('updated_at') is itself a raw site, so
            // neither actually converts the self-assignment.
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

        // The provided inner may be correct yet useless (see INNER_MIN_AREA_RATIO), or
        // missing altogether, or just NULLed above. In all three cases a polygon-derived
        // inner restores the cheap accept the sandwich exists for.
        $this->ensureUsefulInner($msgid);
    }

    /**
     * Replace a missing or uselessly small inner bound with one derived from the stored
     * polygon; keep a useful one untouched. Degraded completed-post rows (POINT outer)
     * are never resurrected. Returns 'kept', 'derived', 'nulled' or 'skipped' so the
     * backfill command can report what happened.
     */
    public function ensureUsefulInner(int $msgid, float $minRatio = self::INNER_MIN_AREA_RATIO): string
    {
        if (!$this->ready()) {
            return 'skipped';
        }

        try {
            // keep-raw: ST_GeometryType/ST_Area GIS expressions; useReadPdo=false (own-write read)
            $row = DB::select(
                'SELECT ST_GeometryType(outer_bound) AS outer_type,
                        inner_bound IS NULL AS missing,
                        COALESCE(ST_Area(inner_bound) / NULLIF(ST_Area(polygon), 0), 0) AS ratio
                   FROM rippling_reach
                  WHERE msgid = ? AND polygon IS NOT NULL AND outer_bound IS NOT NULL',
                [$msgid],
                false
            )[0] ?? null;

            if ($row === null || $row->outer_type === 'POINT') {
                return 'skipped';
            }
            if (!(int) $row->missing && (float) $row->ratio >= $minRatio) {
                return 'kept';
            }
        } catch (\Throwable) {
            // ST_Area can throw on invalid stored geometry; an unmeasurable inner is a
            // suspect inner, so fall through and re-derive it.
        }

        try {
            // keep-raw: embeds the innerExpr GIS derivation; updated_at preserved deliberately.
            // The POINT guard repeats here because the check above can be skipped by its
            // catch: a degraded completed-post row must never get an inner resurrected.
            DB::update(
                'UPDATE rippling_reach
                    SET inner_bound = ' . self::innerExpr('polygon') . ',
                        updated_at = updated_at
                  WHERE msgid = ? AND ST_GeometryType(outer_bound) <> \'POINT\'',
                [$msgid]
            );
        } catch (\Throwable $e) {
            Log::warning("ripple: inner bound derivation failed for msg {$msgid}: {$e->getMessage()}");
            $this->nullInner($msgid);

            return 'nulled';
        }

        [, $innerOk] = $this->verifySandwich($msgid);
        if ($innerOk !== 1) {
            $this->nullInner($msgid);

            return 'nulled';
        }

        return 'derived';
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
            // keep-raw: outerExpr()/innerExpr() render ST_Buffer(ST_Simplify(polygon, ...))
            // - spatial functions with no builder method - plus the same
            // `updated_at = updated_at` self-assignment trick as sync() above.
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
            // keep-raw: ST_SRID(POINT(lng, lat), 3857) is a spatial constructor built
            // from two OTHER columns (lng, lat), which the builder has no method for,
            // plus the `updated_at = updated_at` self-assignment trick used throughout
            // this class (see sync() above for why it must stay raw).
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
            // keep-raw: ST_Contains is a spatial predicate the builder has no method
            // for, applied between two columns (not a column-vs-value comparison a
            // whereColumn-style call could express). useReadPdo=false (the trailing
            // `false`) is preserved: this must read our own just-written row, not a
            // replica that may not have caught up yet.
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
            // keep-raw: the SET assigns rr.outer_bound from a correlated scalar
            // subquery wrapped in COALESCE, built on ST_Union/ST_GeometryType (spatial
            // functions with no builder method), plus the same
            // `rr.updated_at = rr.updated_at` self-assignment trick as elsewhere in
            // this class. The builder can select an aggregate subquery (selectSub) but
            // not use one as an UPDATE ... SET value, so even the JOIN-free parts of
            // this statement have no builder path.
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
            // keep-raw: ST_Envelope(polygon) is a spatial function with no builder
            // method, plus the `updated_at = updated_at` self-assignment trick used
            // throughout this class (see sync() above for why it must stay raw).
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
            // The raw form carried "updated_at = updated_at" to suppress an
            // auto-update column. There is no such column: rippling_reach.updated_at
            // has an empty `extra` in information_schema and no trigger, so the
            // self-assignment was inert and the builder form is equivalent.
            DB::table('rippling_reach')->where('msgid', $msgid)->update(['inner_bound' => null]);
        } catch (\Throwable $e) {
            Log::warning("ripple: bounds inner-null failed for msg {$msgid}: {$e->getMessage()}");
        }
    }
}
