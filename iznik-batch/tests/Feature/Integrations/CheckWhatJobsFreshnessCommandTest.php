<?php

namespace Tests\Feature\Integrations;

use App\Services\WhatJobsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckWhatJobsFreshnessCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('jobs')->delete();
        config(['freegle.whatjobs.feed1' => 'https://feeds.example/whatjobs.xml.gz', 'freegle.whatjobs.max_feed_age_hours' => 48]);
    }

    private function jobPosted(string $postedAt): void
    {
        DB::statement(
            'INSERT INTO jobs (job_reference, title, url, geometry, visible, clickability, posted_at)
             VALUES (?, ?, ?, ST_GeomFromText(?, ?), 1, 0, ?)',
            ['fresh-test-'.uniqid(), 'Driver', 'https://jobs.example/1', WhatJobsService::boxPoly(53.8, -1.5, 53.9, -1.4), config('freegle.srid', 3857), $postedAt]
        );
    }

    public function test_a_feed_with_a_job_from_today_passes(): void
    {
        $this->jobPosted(now()->subDays(5)->toDateTimeString());
        $this->jobPosted(now()->subHours(3)->toDateTimeString());

        $this->artisan('integrations:check-whatjobs-freshness')->assertSuccessful();
    }

    public function test_a_feed_whose_newest_job_is_over_two_days_old_fails_and_says_when_whatjobs_last_changed_it(): void
    {
        $this->jobPosted(now()->subHours(60)->toDateTimeString());
        Http::fake(['feeds.example/*' => Http::response('', 200, ['Last-Modified' => 'Tue, 20 Oct 2026 00:55:23 GMT'])]);

        $this->artisan('integrations:check-whatjobs-freshness')
            ->expectsOutputToContain('Tue, 20 Oct 2026 00:55:23 GMT')
            ->assertFailed();
    }

    public function test_no_jobs_at_all_fails(): void
    {
        $this->artisan('integrations:check-whatjobs-freshness')->assertFailed();
    }

    public function test_without_a_feed_there_is_nothing_to_check(): void
    {
        config(['freegle.whatjobs.feed1' => '']);

        $this->artisan('integrations:check-whatjobs-freshness')->assertSuccessful();
    }
}
