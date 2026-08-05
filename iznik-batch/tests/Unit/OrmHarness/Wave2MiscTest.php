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

    // SELECT jobs.id AS jobid, logs_jobs.id AS lid ... INNER JOIN jobs ON jobs.url = logs_jobs.link
    private const SITE_JOBID_BACKFILL = 'b3ba7ebf8c17';

    // The visualise source sweep: DISTINCT over three joined tables.
    private const SITE_VISUALISE_SOURCE = '9866d94c54a3';

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

    /**
     * Joined on URL, not on id - which is the whole purpose: these are log rows
     * whose jobid was never resolved, matched back to their job by link so the
     * caller can fill it in. Joining on an id here would find nothing, since
     * the id is precisely what is missing.
     */
    public function test_jobid_backfill_candidates(): void
    {
        GoldenSql::assert(self::SITE_JOBID_BACKFILL, fn () => DB::table('logs_jobs')
            ->select('jobs.id as jobid', 'logs_jobs.id as lid')
            ->join('jobs', 'jobs.url', '=', 'logs_jobs.link')
            ->whereNull('logs_jobs.jobid')
            ->whereNotNull('logs_jobs.link')
            ->orderByDesc('logs_jobs.id'));
    }

    public function test_visualise_source_rows(): void
    {
        GoldenSql::assert(self::SITE_VISUALISE_SOURCE, fn () => DB::table('messages')
            ->distinct()
            ->select(
                'messages.id',
                'a.id as attid',
                'messages.fromuser',
                'messages_by.userid as touser',
                'messages_by.timestamp'
            )
            ->join('messages_by', 'messages.id', '=', 'messages_by.msgid')
            ->join('messages_attachments as a', 'messages.id', '=', 'a.msgid')
            ->where('messages_by.timestamp', '>', '2026-01-01 00:00:00')
            ->where('messages.type', 'Offer')
            ->whereNotNull('messages_by.userid')
            ->orderByDesc('messages_by.timestamp'));
    }
}
