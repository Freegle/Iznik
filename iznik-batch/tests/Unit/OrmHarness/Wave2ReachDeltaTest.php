<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the reach-shadow delta select.
 *
 * Two details the golden is holding, both of which change which rows come back:
 *
 *   ->leftJoin(), not ->join(). The query exists to find rippling_reach rows
 *   with NO shadow row yet; an inner join excludes exactly those.
 *
 *   The OR is grouped. Flat, it would bind against the whole WHERE and the
 *   LIMIT would then be applied to a different row set.
 */
class Wave2ReachDeltaTest extends TestCase
{
    // SELECT rr.msgid FROM rippling_reach rr LEFT JOIN rippling_reach_shadow s
    //   ON s.msgid = rr.msgid WHERE s.msgid IS NULL OR rr.updated_at >= ? LIMIT ?
    private const SITE_REACH_DELTA = '0f2ac58b064f';

    public function test_reach_shadow_delta(): void
    {
        GoldenSql::assert(self::SITE_REACH_DELTA, fn () => DB::table('rippling_reach as rr')
            ->select('rr.msgid')
            ->leftJoin('rippling_reach_shadow as s', 's.msgid', '=', 'rr.msgid')
            ->where(function ($q) {
                $q->whereNull('s.msgid')
                  ->orWhere('rr.updated_at', '>=', '2026-01-01 00:00:00');
            })
            ->limit(1000));
    }
}
