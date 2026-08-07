<?php

namespace App\Services\Ripple;

use App\Database\Expressions\Coalesce;
use App\Database\Expressions\Comparison;
use App\Database\Expressions\Point;
use App\Database\Expressions\StBuffer;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StEnvelope;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StGeomFromText;
use App\Database\Expressions\StSimplify;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\StUnion;
use App\Database\Expressions\Subquery;
use App\Database\Expressions\Value;
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

    private const SRID = 3857;

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
            // updated_at = updated_at was a self-assignment to suppress an ON UPDATE
            // auto-bump - rippling_reach.updated_at has no such trigger (empty `extra`
            // in information_schema, verified), so simply omitting it here is
            // equivalent, exactly as nullInner() below established.
            DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->update([
                    'outer_bound' => new StGeomFromText(Value::of($outerWkt), self::SRID),
                    'inner_bound' => $innerWkt !== null
                        ? new StGeomFromText(Value::of($innerWkt), self::SRID)
                        : null,
                ]);
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
            // Same ST_Buffer(ST_Simplify(polygon, TOLERANCE), ±TOLERANCE) shape as
            // outerExpr()/innerExpr() (kept string-returning for MigrateReachBoundsSchemaCommand
            // and ExpandService's raw-SQL callers), expressed structurally here. updated_at
            // omitted - see sync() above for why the self-assignment was always a no-op.
            DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->update([
                    'outer_bound' => new StBuffer(new StSimplify('polygon', self::TOLERANCE), self::TOLERANCE),
                    'inner_bound' => new StBuffer(new StSimplify('polygon', self::TOLERANCE), -self::TOLERANCE),
                ]);
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
            // updated_at omitted - see sync() above.
            DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->update([
                    'outer_bound' => new StSrid(new Point('lng', 'lat'), self::SRID),
                    'inner_bound' => null,
                ]);
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
            // Two ->exists() reads replace the one-row SELECT: each is the truthiness
            // test the raw form's boolean column was ("... AS o"/"... AS i"), and
            // ->useWritePdo() preserves useReadPdo=false from the raw form - this must
            // read our own just-written row, not a replica that may not have caught up.
            // Both share this one try/catch so a throw from either yields [0, 0], exactly
            // as a throw from the single original query did.
            $outerOk = DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->where(new StContains('outer_bound', 'polygon'))
                ->useWritePdo()
                ->exists();

            $innerOk = DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->where(function ($q) {
                    $q->whereNull('inner_bound')
                        ->orWhere(new StContains('polygon', 'inner_bound'));
                })
                ->useWritePdo()
                ->exists();

            return [(int) $outerOk, (int) $innerOk];
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
            // A correlated scalar subquery as an UPDATE ... SET value renders directly
            // via Builder::update()'s own Builder-detection - but only at the top level
            // of a value position, not nested inside another Expression's operand
            // (COALESCE here). Subquery (App\Database\Expressions\Subquery) bridges
            // that gap: it embeds a fully-built, binding-free Builder as SQL text so it
            // can sit inside Coalesce's operand list. The subquery's own predicates are
            // therefore built with this namespace's Comparison/Value::of() instead of
            // the builder's normal bound where() - see Subquery's docblock for why
            // (bindings have nowhere to attach once nested this way). updated_at
            // omitted - see sync() above.
            $originGroupArea = DB::table('messages_groups as mg')
                ->join('groups as g', 'g.id', '=', 'mg.groupid')
                ->whereColumn('mg.msgid', 'rr.msgid')
                ->where(new Comparison('mg.deleted', '=', 0))
                ->whereNotNull('g.polyindex')
                ->where(new Comparison(new StGeometryType('g.polyindex'), '<>', Value::of('POINT')))
                ->orderBy('mg.arrival')
                ->limit(1)
                ->select(new StUnion('rr.outer_bound', 'g.polyindex'));

            DB::table('rippling_reach as rr')
                ->where('rr.msgid', $msgid)
                ->update([
                    'rr.outer_bound' => new Coalesce(new Subquery($originGroupArea), 'rr.outer_bound'),
                ]);
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
            // updated_at omitted - see sync() above.
            DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->update([
                    'outer_bound' => new StEnvelope('polygon'),
                    'inner_bound' => null,
                ]);
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
