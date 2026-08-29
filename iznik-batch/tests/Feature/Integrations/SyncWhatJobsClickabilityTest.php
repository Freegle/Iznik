<?php

namespace Tests\Feature\Integrations;

use App\Services\WhatJobsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for WhatJobsService::analyseClickability() and updateClickability().
 *
 * These methods use TRUNCATE TABLE which causes an implicit commit in MySQL,
 * breaking DatabaseTransactions isolation. Manual setUp/tearDown cleanup is used instead.
 */
class SyncWhatJobsClickabilityTest extends TestCase
{
    // No DatabaseTransactions trait — analyseClickability() uses TRUNCATE which commits the transaction.

    private int $srid;
    private string $geom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srid = config('freegle.srid', 3857);
        $this->geom = WhatJobsService::boxPoly(53.8, -1.5, 53.9, -1.4);
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        DB::table('logs_jobs')->where('link', 'like', 'https://clickability-test.%')->delete();
        DB::statement("DELETE FROM jobs WHERE job_reference LIKE 'clickability-test-%'");
        DB::table('jobs_keywords')->truncate();
    }

    private function insertJob(string $ref, string $title, string $url): int
    {
        DB::statement(
            'INSERT INTO jobs (job_reference, title, url, geometry, visible, clickability)
             VALUES (?, ?, ?, ST_GeomFromText(?, ?), 1, 0)',
            [$ref, $title, $url, $this->geom, $this->srid]
        );
        return (int) DB::getPdo()->lastInsertId();
    }

    /** @test */
    public function test_analyseClickability_populates_keywords_from_clicked_jobs(): void
    {
        $jobId = $this->insertJob(
            'clickability-test-1',
            'Web Developer London',
            'https://clickability-test.example/job/1'
        );

        DB::table('logs_jobs')->insert([
            'jobid'     => $jobId,
            'link'      => 'https://clickability-test.example/job/1',
            'timestamp' => now(),
        ]);

        (new WhatJobsService())->analyseClickability();

        $keywords = DB::table('jobs_keywords')->pluck('keyword')->toArray();
        $this->assertNotEmpty($keywords);
        $this->assertContains('web developer', $keywords);
    }

    /** @test */
    public function test_analyseClickability_backfills_jobid_from_url(): void
    {
        $jobId = $this->insertJob(
            'clickability-test-2',
            'Software Engineer',
            'https://clickability-test.example/job/2'
        );

        DB::table('logs_jobs')->insert([
            'jobid'     => null,
            'link'      => 'https://clickability-test.example/job/2',
            'timestamp' => now(),
        ]);
        $logId = (int) DB::getPdo()->lastInsertId();

        (new WhatJobsService())->analyseClickability();

        $updated = DB::table('logs_jobs')->where('id', $logId)->first();
        $this->assertEquals($jobId, $updated->jobid);
    }

    /** @test */
    public function test_updateClickability_scores_jobs_with_matching_keywords(): void
    {
        DB::table('jobs_keywords')->insert(['keyword' => 'web developer', 'count' => 5]);

        $jobId = $this->insertJob(
            'clickability-test-3',
            'Web Developer London',
            'https://clickability-test.example/job/3'
        );

        (new WhatJobsService())->updateClickability();

        $job = DB::table('jobs')->where('id', $jobId)->first();
        $this->assertGreaterThan(0, $job->clickability);
    }

    /** @test */
    public function test_updateClickability_leaves_zero_for_unmatched_jobs(): void
    {
        DB::table('jobs_keywords')->insert(['keyword' => 'nurse practitioner', 'count' => 10]);

        $jobId = $this->insertJob(
            'clickability-test-4',
            'Unicorn Wrangler',
            'https://clickability-test.example/job/4'
        );

        (new WhatJobsService())->updateClickability();

        $job = DB::table('jobs')->where('id', $jobId)->first();
        $this->assertEquals(0, $job->clickability);
    }

    /**
     * @test
     *
     * sync() now scores clickability in PHP and writes it on the batched INSERT
     * (insertJobs) instead of a post-swap per-row UPDATE pass, so the ~1M-row
     * table is never re-touched. This asserts the score lands on the row at
     * insert time — the same title-keyword scoring updateClickability applied
     * (a matching title > 0, an unmatched title 0), now folded into the insert.
     */
    public function test_insertJobs_scores_clickability_on_insert_from_keywords(): void
    {
        // getMaxish() 95th-pct over a single keyword returns its count (5), so a
        // title whose pair matches scores 5/5 = 1.0; an unmatched title scores 0.
        DB::table('jobs_keywords')->insert(['keyword' => 'web developer', 'count' => 5]);

        $svc = new WhatJobsService();
        $svc->prepareTempTable();
        $svc->insertJobs([
            $this->jobRow('clickability-test-insert-1', 'Web Developer London'),
            $this->jobRow('clickability-test-insert-2', 'Unicorn Wrangler'),
        ], $this->srid);

        $matched   = DB::table('jobs_new')->where('job_reference', 'clickability-test-insert-1')->first();
        $unmatched = DB::table('jobs_new')->where('job_reference', 'clickability-test-insert-2')->first();

        $this->assertGreaterThan(0, (float) $matched->clickability, 'title matching a seeded keyword should score > 0 at insert');
        $this->assertEquals(0.0, (float) $unmatched->clickability, 'title with no keyword match should score 0 at insert');

        DB::statement('DROP TABLE IF EXISTS jobs_new');
    }

    /** @test */
    public function test_insertJobs_handles_null_title(): void
    {
        // parseFeed yields title=null for feed jobs with an empty <title> and
        // jobs.title is nullable; scoring must treat that as "no keyword
        // signal", not crash (2026-08-29: a null title TypeError'd getKeywords()
        // and killed the whole sync, freezing jobs.seenat for >24h).
        DB::table('jobs_keywords')->insert(['keyword' => 'web developer', 'count' => 5]);

        $svc = new WhatJobsService();
        $svc->prepareTempTable();
        $svc->insertJobs([
            $this->jobRow('clickability-test-insert-3', null),
        ], $this->srid);

        $row = DB::table('jobs_new')->where('job_reference', 'clickability-test-insert-3')->first();

        $this->assertNotNull($row, 'a feed job with a null title should still insert');
        $this->assertEquals(0.0, (float) $row->clickability, 'null title carries no keyword signal so scores 0');

        DB::statement('DROP TABLE IF EXISTS jobs_new');
    }

    /** Full job-row shape insertJobs() consumes (mirrors parseFeed's yield). */
    private function jobRow(string $ref, ?string $title): array
    {
        return [
            'location' => 'Leeds', 'title' => $title, 'city' => 'Leeds', 'state' => 'West Yorkshire',
            'zip' => null, 'country' => 'UK', 'job_type' => null, 'posted_at' => null,
            'job_reference' => $ref, 'company' => 'Test Co', 'category' => 'IT',
            'url' => 'https://clickability-test.example/' . $ref, 'body' => 'body', 'cpc' => 0.5,
            'geometry' => $this->geom, 'clickability' => 1, 'bodyhash' => md5($ref),
            'seenat' => now()->format('Y-m-d H:i:s'), 'visible' => 1, 'canonical_title' => null,
        ];
    }
}
