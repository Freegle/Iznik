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
}
