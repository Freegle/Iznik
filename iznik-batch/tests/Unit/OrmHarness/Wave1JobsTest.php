<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, jobs: Layer 1 parity for the converted sites in
 * app/Services/WhatJobsService.php.
 *
 * geocodePostcode's six-aggregate SELECT (AVG/MIN/MAX of lat and lng, each
 * aliased) is deliberately not here and stays raw: the builder has no non-raw
 * way to express several aliased aggregates in one statement, so converting it
 * would mean ->select(DB::raw(...)) - which is itself a raw site and would move
 * the site between surfaces rather than remove it. Recorded as keep-raw.
 */
class Wave1JobsTest extends TestCase
{
    // Two call sites of the same statement: SELECT keyword, count FROM jobs_keywords
    private const SITE_KEYWORDS_A = 'cd3f21359d36';
    private const SITE_KEYWORDS_B = 'fc9b72f253b8';

    // DELETE FROM logs_jobs WHERE timestamp < ?
    private const SITE_PRUNE_LOGS = '05af6812abd7';

    // UPDATE logs_jobs SET jobid = ? WHERE id = ?
    private const SITE_BACKFILL_JOBID = 'c47a7643ee63';

    // SELECT id, title FROM jobs
    private const SITE_ALL_JOBS = '1de2f2022f0c';

    // UPDATE jobs SET clickability = ? WHERE id = ?
    private const SITE_SET_CLICKABILITY = 'ae53f7c6596e';

    public function test_keyword_frequencies(): void
    {
        $build = fn () => DB::table('jobs_keywords')->select('keyword', 'count');

        GoldenSql::assert(self::SITE_KEYWORDS_A, $build);
        GoldenSql::assert(self::SITE_KEYWORDS_B, $build);
    }

    public function test_prune_old_job_logs(): void
    {
        GoldenSql::assertDelete(self::SITE_PRUNE_LOGS, fn () => DB::table('logs_jobs')
            ->where('timestamp', '<', '2026-01-01 00:00:00'));
    }

    public function test_backfill_jobid(): void
    {
        GoldenSql::assertUpdate(self::SITE_BACKFILL_JOBID, fn () => [
            DB::table('logs_jobs')->where('id', 1),
            ['jobid' => 2],
        ]);
    }

    public function test_all_jobs(): void
    {
        GoldenSql::assert(self::SITE_ALL_JOBS, fn () => DB::table('jobs')
            ->select('id', 'title'));
    }

    public function test_set_clickability(): void
    {
        GoldenSql::assertUpdate(self::SITE_SET_CLICKABILITY, fn () => [
            DB::table('jobs')->where('id', 1),
            ['clickability' => 0.5],
        ]);
    }
}
