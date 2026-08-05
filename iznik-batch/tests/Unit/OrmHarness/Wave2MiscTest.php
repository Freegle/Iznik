<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: orphaned-user log count, the visualise index write, and the clicked
 * job titles.
 */
class Wave2MiscTest extends TestCase
{
    // SELECT COUNT(*) FROM logs LEFT JOIN users ... WHERE users.id IS NULL
    private const SITE_ORPHAN_USER_LOGS = '9a7cb23af1e0';

    // INSERT IGNORE INTO visualise (10 columns)
    private const SITE_VISUALISE = 'ae7f5fcaf65f';

    // SELECT DISTINCT jobs.title FROM logs_jobs INNER JOIN jobs ...
    private const SITE_CLICKED_TITLES = '808ef0c86a20';

    /**
     * The third anti-join in this service alone: logs whose USER has been
     * deleted, as distinct from the sibling count for logs whose MESSAGE has.
     * Both drive what a purge dry run reports.
     */
    public function test_orphaned_user_log_count(): void
    {
        GoldenSql::assert(self::SITE_ORPHAN_USER_LOGS, function () {
            $q = DB::table('logs')
                ->leftJoin('users', 'users.id', '=', 'logs.user')
                ->where('timestamp', '<', '2026-01-01')
                ->whereNotNull('logs.user')
                ->whereNull('users.id');
            $q->aggregate = ['function' => 'count', 'columns' => ['*']];

            return $q;
        });
    }

    /**
     * Ten columns, and their ORDER is what assertInsert exists to hold:
     * fromlat/fromlng and tolat/tolng are adjacent pairs of the same type, so
     * transposing a pair would insert plausible-looking coordinates pointing
     * at the wrong place, with nothing to catch it downstream.
     */
    public function test_visualise_write(): void
    {
        GoldenSql::assertInsertOrIgnore(self::SITE_VISUALISE, fn () => [
            DB::table('visualise'),
            [
                'msgid' => 1,
                'attid' => 2,
                'timestamp' => '2026-01-01 00:00:00',
                'fromuser' => 3,
                'touser' => 4,
                'fromlat' => 51.5,
                'fromlng' => -0.1,
                'tolat' => 52.5,
                'tolng' => -1.1,
                'distance' => 1000,
            ],
        ]);
    }

    public function test_clicked_job_titles(): void
    {
        GoldenSql::assert(self::SITE_CLICKED_TITLES, fn () => DB::table('logs_jobs')
            ->distinct()
            ->select('jobs.title')
            ->join('jobs', 'logs_jobs.jobid', '=', 'jobs.id'));
    }
}
