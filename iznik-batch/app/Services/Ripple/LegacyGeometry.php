<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\Schema;

/**
 * Which schema era is rippling_reach in? The cells migration
 * (plans/2026-08-24-rippling-reach-raster-storage.md, Stage 3) eventually
 * DROPS polygon/max_polygon/overflow_bounds (and the #1402 dedup hash columns
 * and shared table with them): after that every fallback that reads them must
 * be dead, and these guards are what kill them. Checked once per process,
 * like ExpandService's own column memos: the operator drops the columns only
 * after the cells backfill reaches 100%, and the batch workers are restarted
 * afterwards.
 *
 * The Go API carries the same pair (rippling.LegacyPolygonReady /
 * LegacyOverflowReady); the two languages must agree about what each era
 * means, which is why the guard is a column-existence fact rather than a
 * config flag someone could set differently per service.
 */
class LegacyGeometry
{
    private static ?bool $polygon = null;
    private static ?bool $overflow = null;

    /** Does rippling_reach still carry the legacy polygon geometry columns? */
    public static function polygonReady(): bool
    {
        if (self::$polygon === null) {
            try {
                self::$polygon = Schema::hasColumn('rippling_reach', 'polygon');
            } catch (\Throwable) {
                self::$polygon = false;
            }
        }

        return self::$polygon;
    }

    /** Does rippling_reach still carry the legacy overflow_bounds ring WKT? */
    public static function overflowReady(): bool
    {
        if (self::$overflow === null) {
            try {
                self::$overflow = Schema::hasColumn('rippling_reach', 'overflow_bounds');
            } catch (\Throwable) {
                self::$overflow = false;
            }
        }

        return self::$overflow;
    }

    /** Test hook: forget the memo so a schema change inside a test is seen. */
    public static function reset(): void
    {
        self::$polygon = null;
        self::$overflow = null;
    }

    /**
     * Test hook: pretend to be in a given era without touching the schema.
     *
     * The two eras BOTH have to be tested, and dropping the columns from the
     * test database would only swap which one is covered - the transition era
     * is the code that actually runs on production first, for as long as the
     * backfill takes, so it cannot be the untested one. Overriding the answer
     * lets a test exercise the cells-only branches against a schema that still
     * has the columns; the branches themselves never name a dropped column, so
     * the SQL they emit is valid either way, and that is exactly the property
     * worth asserting.
     *
     * The migration proves the schema half separately, by running.
     */
    public static function fake(?bool $polygon = null, ?bool $overflow = null): void
    {
        self::$polygon = $polygon;
        self::$overflow = $overflow;
    }
}
