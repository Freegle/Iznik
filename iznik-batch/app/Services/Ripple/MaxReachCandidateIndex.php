<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * Is rippling_reach_maxreach_candidates in place yet?
 *
 * MaxReachService's candidate scan has two correct forms and which one is
 * correct depends on the schema, not on a preference:
 *
 *  - WITH the index: filter on has_max_reach and let the planner use it. That
 *    is a one-row lookup with no filesort.
 *  - WITHOUT it: filter on max_polygon_cells IS NULL and FORCE INDEX onto the
 *    status index, which is PR #1404's fix - 13,696 rows and a filesort, but
 *    far better than the whole-index walk the planner picks unaided.
 *
 * Getting this wrong in either direction is bad in a specific way. Name
 * has_max_reach before the migration has run and the query dies on an unknown
 * column, taking first-reply match mail with it. Keep #1404's FORCE INDEX after
 * the migration has run and the planner is PINNED to the status index, so the
 * new index sits there unused and the scan is measurably WORSE than doing
 * nothing (15,177 rows against the composite's 1). A hint and an index are
 * alternatives, not layers.
 *
 * So the answer is a schema fact, asked once per process, exactly like
 * LegacyGeometry - production applies DDL by hand under RSU rather than via
 * artisan, so code genuinely does run against both schemas, and the workers are
 * restarted after the DDL lands.
 */
class MaxReachCandidateIndex
{
    private static ?bool $ready = null;

    /** Does the composite candidate index exist on rippling_reach? */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                $row = DB::selectOne(
                    "SELECT COUNT(*) AS n FROM information_schema.statistics
                      WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                        AND index_name = 'rippling_reach_maxreach_candidates'"
                );
                self::$ready = (int) ($row->n ?? 0) > 0;
            } catch (\Throwable) {
                // Unreadable schema means take the older, always-valid form:
                // it names no column that might be missing.
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** Test hook: forget the memo so a schema change inside a test is seen. */
    public static function reset(): void
    {
        self::$ready = null;
    }

    /** Test hook: pretend, so both forms are covered against one schema. */
    public static function fake(?bool $ready = null): void
    {
        self::$ready = $ready;
    }
}
