<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content-addressed sharing of rippling_reach's big geometry blobs
 * (plans/2026-08-23-rippling-reach-polygon-dedup.md): each distinct geometry is
 * stored once in rippling_reach_geom keyed by MD5 of its WKB, and reach rows
 * point at it via polygon_hash / max_polygon_hash.
 *
 * This class is the ONE definition of that hash and of the reader fallback, so
 * writers, readers, the backfill and the checker cannot disagree about
 * canonicalisation. The hash is always computed BY MYSQL from the exact bytes
 * being stored (never in PHP), so driver/text-encoding differences cannot
 * split it.
 *
 * Invariants every caller preserves:
 *  - A non-NULL hash column always matches the blob written by the same
 *    statement. Anything that mutates a blob in place (the ST_Difference
 *    clips) must NULL the hash in that same statement, then re-point with
 *    upsertFromRow + rehashFromRow. A crash between leaves the hash NULL,
 *    which readers treat as "use the blob" - always safe.
 *  - The geom row is upserted BEFORE any statement that sets its hash (the FK
 *    on the hash columns enforces this). Orphan geom rows from a crash in that
 *    window are harmless; ripple:gc-reach-geometry sweeps them.
 *  - Readers never assume the geom row: LEFT JOIN + COALESCE(g.geom, blob),
 *    correct before backfill, during it, and after ripple:drain-deduped-blobs
 *    replaces backfilled polygon blobs with DRAIN_SENTINEL_WKT.
 *  - There is NO reference counter anywhere, deliberately: the messages FK
 *    cascade and four explicit delete paths would each have to keep one
 *    accurate, and on Galera a counter like that only drifts. Upserts are
 *    no-op ODKU (idempotent); GC proves non-reference by anti-join.
 */
class GeomShareService
{
    public const SRID = 3857;

    /**
     * What ripple:drain-deduped-blobs leaves in `polygon` once the geometry
     * lives in rippling_reach_geom. A sentinel rather than NULL because the
     * column is NOT NULL with a live SPATIAL index (an R-tree column cannot be
     * nullable, and dropping the index on prod means a 50 GB table rebuild).
     * Same idiom as the degenerate-POINT outer_bound for completed posts.
     * Readers never test it: a drained row has a non-NULL hash, so
     * COALESCE prefers the shared geometry.
     */
    public const DRAIN_SENTINEL_WKT = 'POINT(0 0)';

    /** Memoized column-existence check so a pre-migration deploy is a no-op. */
    private static ?bool $ready = null;

    /** Has the rippling_reach_geom migration run? Everything here no-ops if not. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('rippling_reach_geom')
                    && Schema::hasColumn('rippling_reach', 'polygon_hash');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** Test-only: forget the memoized readiness check. */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    /**
     * The hash of a WKT bind, as an SQL expression consuming ONE ? (the same
     * WKT the sibling geometry column is being set from). For INSERT column
     * lists, where the stored blob cannot be referenced by name.
     */
    public static function hashOfWktExpr(): string
    {
        return 'UNHEX(MD5(ST_AsBinary(ST_GeomFromText(?, ' . self::SRID . '))))';
    }

    /**
     * The hash of a geometry column, as an SQL expression. In a single-table
     * UPDATE, SET clauses are applied left to right, so
     * `SET polygon = <new>, polygon_hash = hashOfColumnExpr('polygon')` hashes
     * the NEW bytes with a single WKT parse - byte-consistency by construction.
     * NOT valid in multi-table UPDATEs, where MySQL does not guarantee
     * assignment order.
     */
    public static function hashOfColumnExpr(string $col): string
    {
        return 'UNHEX(MD5(ST_AsBinary(' . $col . ')))';
    }

    /**
     * Ensure the shared row for a WKT exists. Idempotent and race-free: the
     * ODKU only ever inserts or leaves the existing row byte-identical
     * (identical hash means identical WKB), so concurrent writers - backfill
     * shards, the maxreach cron, the Go clip - cannot conflict. Called before
     * any statement that sets a hash column (the FK insists).
     *
     * The duplicate arm refreshes createdat rather than being a pure no-op:
     * createdat is the GC age clock, and it must mean "last touched", not
     * "first created". A shared geometry legitimately drops to zero
     * references and is later resurrected by another post at the same origin
     * and tick; if its clock never moved, the sweep could delete it between
     * this upsert and the statement that references it. Refreshed, either
     * order is safe: upsert first re-arms the full grace, GC-delete first
     * just makes this upsert a fresh INSERT.
     */
    public static function upsertFromWkt(string $wkt): void
    {
        // keep-raw: INSERT..SELECT with spatial/hash expressions - the builder cannot render these
        DB::statement(
            'INSERT INTO rippling_reach_geom (hash, geom)
             SELECT UNHEX(MD5(ST_AsBinary(g.g))), g.g
               FROM (SELECT ST_GeomFromText(?, ' . self::SRID . ') AS g) g
             ON DUPLICATE KEY UPDATE createdat = CURRENT_TIMESTAMP',
            [$wkt]
        );
    }

    /**
     * Ensure the shared row for a blob ALREADY STORED on a reach row exists,
     * hashing the stored bytes themselves. The re-point half is
     * rehashFromRow; backfill and the post-clip re-point use the pair.
     */
    public static function upsertFromRow(int $msgid, string $col): void
    {
        self::assertColumn($col);
        // keep-raw: INSERT..SELECT from the row's own stored geometry
        DB::statement(
            "INSERT INTO rippling_reach_geom (hash, geom)
             SELECT UNHEX(MD5(ST_AsBinary($col))), $col
               FROM rippling_reach
              WHERE msgid = ? AND $col IS NOT NULL
             ON DUPLICATE KEY UPDATE createdat = CURRENT_TIMESTAMP",
            [$msgid]
        );
    }

    /**
     * Point a reach row's hash at its own stored blob. updated_at is held
     * still: this changes no geometry, and a bulk pass that bumped it once
     * generated 38k+ notification emails.
     */
    public static function rehashFromRow(int $msgid, string $col): void
    {
        self::assertColumn($col);
        // keep-raw: UPDATE with spatial/hash expressions in SET
        DB::statement(
            "UPDATE rippling_reach
                SET {$col}_hash = UNHEX(MD5(ST_AsBinary($col))),
                    updated_at = updated_at
              WHERE msgid = ? AND $col IS NOT NULL",
            [$msgid]
        );
    }

    /**
     * Reader join fragment for one hash column, or '' pre-migration. $rowAlias
     * must already be bound in the enclosing query ('rr', 'r2', or the bare
     * table name).
     */
    public static function joinSql(string $rowAlias, string $col, string $geomAlias): string
    {
        self::assertColumn($col);
        if (!self::ready()) {
            return '';
        }

        return " LEFT JOIN rippling_reach_geom $geomAlias ON $geomAlias.hash = $rowAlias.{$col}_hash";
    }

    /**
     * The geometry a reader should test: the shared row when the hash points at
     * one, else the blob on the row. Pre-migration it is just the blob, so
     * every reader keeps working against an unmigrated schema.
     */
    public static function sourceExpr(string $rowAlias, string $col, string $geomAlias): string
    {
        self::assertColumn($col);
        if (!self::ready()) {
            return "$rowAlias.$col";
        }

        return "COALESCE($geomAlias.geom, $rowAlias.$col)";
    }

    /**
     * SQL: true when a row's blob has been drained to the sentinel. Byte
     * comparison via MD5-of-WKB because MySQL does not support = on GEOMETRY
     * values; the same canonicalisation as the hash columns, so this cannot
     * disagree with them.
     */
    public static function drainedExpr(string $rowAlias, string $col): string
    {
        self::assertColumn($col);

        return "MD5(ST_AsBinary($rowAlias.$col)) = MD5(ST_AsBinary(ST_GeomFromText('"
            . self::DRAIN_SENTINEL_WKT . "', " . self::SRID . ')))';
    }

    /**
     * The columns that may be shared. An allowlist, not validation of caller
     * input - column names are interpolated into SQL, so a typo must fail loud
     * here rather than parse as something else there.
     */
    private static function assertColumn(string $col): void
    {
        if (!in_array($col, ['polygon', 'max_polygon'], true)) {
            throw new \InvalidArgumentException("not a shareable geometry column: $col");
        }
    }
}
