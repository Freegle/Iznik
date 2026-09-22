<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 3: the DATEDIFF(NOW(), col) < N recency filters.
 *
 * These diverge from their goldens more than most conversions do - the raw SQL
 * asked MySQL for a calendar-day difference, the builder asks for a date
 * comparison against a cutoff computed in PHP - so each carries an approved
 * diff. The equivalence is not asserted, it was measured:
 *
 *   DATEDIFF(NOW(), ts) >= N   is   DATE(ts) <= today - N days
 *
 * verified against MySQL over a fixture that included same-calendar-day rows at
 * 00:01 and 23:59, which is exactly where a 24-hour-based rewrite diverges.
 * DATEDIFF counts calendar-day BOUNDARIES, not elapsed 24-hour periods, so
 * ->whereDate() against today() is correct and ->where() against now() would
 * silently shift the cutoff.
 *
 * Moving the clock from MySQL to PHP is safe here specifically because both
 * share one UTC clock in this deployment - app.timezone=UTC, php
 * date.timezone=UTC, MySQL SYSTEM resolving to the same wall clock, measured
 * one second apart. That was the premise the original keep-raw reason got
 * wrong.
 */
class Wave3DatediffTest extends TestCase
{
    private const SITE_RECENT_NEWSFEED = 'ea03606b98f1';
    private const SITE_CACHED_PREVIEW_DRYRUN = 'd3579da9736a';
    private const SITE_CACHED_PREVIEW = '33219aae3e9a';

    private const TODAY = '2026-01-08';

    public function test_recent_newsfeed_entries(): void
    {
        GoldenSql::assert(self::SITE_RECENT_NEWSFEED, fn () => DB::table('newsfeed')
            ->select('id', 'message')
            ->whereDate('timestamp', '>', '2026-01-06'));
    }

    /**
     * Two call sites, one statement: the dry-run path and the live path both
     * look for a cached preview retrieved within the last week.
     */
    public function test_cached_link_preview(): void
    {
        $build = fn () => DB::table('link_previews')
            ->select('id')
            ->where('url', 'https://example.com')
            ->whereDate('retrieved', '>', '2026-01-01');

        GoldenSql::assert(self::SITE_CACHED_PREVIEW_DRYRUN, $build);
        GoldenSql::assert(self::SITE_CACHED_PREVIEW, $build);
    }
}
