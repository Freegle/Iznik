<?php

namespace Tests\Feature\Partnerships;

use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsAuthorityStats;
use Tests\TestCase;

/**
 * The Partnerships page cannot wait minutes for a council spreadsheet inside a web request,
 * so it queues the work here. These tests cover the queue mechanics and that a real
 * spreadsheet ends up in the database ready to download.
 */
class StatsJobRunnerCommandTest extends TestCase
{
    use SeedsAuthorityStats;

    private function queueJob(string $authorityIds, string $quarter = '2025-05-15'): int
    {
        return (int) DB::table('partnerships_statsjobs')->insertGetId([
            'authorityids' => $authorityIds,
            'quarter' => $quarter,
            'status' => 'Pending',
            'requested' => now(),
        ]);
    }

    public function test_no_queued_jobs_is_a_noop(): void
    {
        // Other tests share this database, so pin the "nothing to do" case by parking any
        // jobs that happen to be queued rather than assuming the table is empty.
        DB::table('partnerships_statsjobs')->where('status', 'Pending')->update(['status' => 'Ready']);

        $this->artisan('partnerships:stats:run')
            ->expectsOutputToContain('No queued statistics jobs')
            ->assertExitCode(0);
    }

    public function test_renders_and_stores_a_spreadsheet(): void
    {
        $this->seedAuthorityScenario();
        $jobId = $this->queueJob((string) $this->authorityId);

        $this->artisan('partnerships:stats:run')->assertExitCode(0);

        $job = DB::table('partnerships_statsjobs')->find($jobId);
        $this->assertSame('Ready', $job->status, 'the job finishes ready to download');
        $this->assertNotNull($job->completed);

        $files = DB::table('partnerships_statsfiles')->where('jobid', $jobId)->get();
        $this->assertCount(1, $files, 'one spreadsheet per authority');

        $file = $files->first();
        $this->assertSame($this->authorityId, (int) $file->authorityid);
        $this->assertStringEndsWith('.xlsx', $file->filename);
        $this->assertGreaterThan(0, $file->size);
        // A real xlsx is a zip, so it starts with the zip magic bytes.
        $this->assertSame('PK', substr($file->content, 0, 2), 'the stored bytes are a real spreadsheet');
        $this->assertSame(strlen($file->content), (int) $file->size);
    }

    public function test_spreadsheet_is_named_after_the_authority(): void
    {
        $this->seedAuthorityScenario();
        $jobId = $this->queueJob((string) $this->authorityId);

        $this->artisan('partnerships:stats:run')->assertExitCode(0);

        $filename = DB::table('partnerships_statsfiles')->where('jobid', $jobId)->value('filename');
        $this->assertStringContainsString('Test Authority (B)', $filename);
    }

    public function test_unknown_authority_fails_the_job_with_a_reason(): void
    {
        $jobId = $this->queueJob('999999999');

        $this->artisan('partnerships:stats:run')->assertExitCode(0);

        $job = DB::table('partnerships_statsjobs')->find($jobId);
        $this->assertSame('Failed', $job->status);
        $this->assertNotEmpty($job->error, 'the page shows why it failed');
        $this->assertSame(0, DB::table('partnerships_statsfiles')->where('jobid', $jobId)->count());
    }

    public function test_job_with_no_authority_ids_fails(): void
    {
        $jobId = $this->queueJob('');

        $this->artisan('partnerships:stats:run')->assertExitCode(0);

        $job = DB::table('partnerships_statsjobs')->find($jobId);
        $this->assertSame('Failed', $job->status);
        $this->assertStringContainsString('No authority IDs', $job->error);
    }

    public function test_a_claimed_job_is_not_run_again(): void
    {
        $this->seedAuthorityScenario();
        $jobId = $this->queueJob((string) $this->authorityId);

        $this->artisan('partnerships:stats:run')->assertExitCode(0);
        $this->assertSame(1, DB::table('partnerships_statsfiles')->where('jobid', $jobId)->count());

        // A second pass must not pick the finished job up again and double the files.
        $this->artisan('partnerships:stats:run')->assertExitCode(0);
        $this->assertSame(1, DB::table('partnerships_statsfiles')->where('jobid', $jobId)->count());
    }

    public function test_only_the_requested_number_of_jobs_runs_per_pass(): void
    {
        DB::table('partnerships_statsjobs')->where('status', 'Pending')->update(['status' => 'Ready']);

        $first = $this->queueJob('999999999');
        $second = $this->queueJob('999999998');

        $this->artisan('partnerships:stats:run', ['--limit' => 1])->assertExitCode(0);

        $this->assertSame('Failed', DB::table('partnerships_statsjobs')->find($first)->status);
        $this->assertSame('Pending', DB::table('partnerships_statsjobs')->find($second)->status,
            'the second job waits for the next pass');
    }

    public function test_partial_failure_still_delivers_what_worked(): void
    {
        $this->seedAuthorityScenario();
        // One good council and one that does not exist.
        $jobId = $this->queueJob($this->authorityId . ',999999999');

        $this->artisan('partnerships:stats:run')->assertExitCode(0);

        $job = DB::table('partnerships_statsjobs')->find($jobId);
        $this->assertSame('Ready', $job->status, 'the spreadsheet that did render is still worth having');
        $this->assertStringContainsString('999999999', (string) $job->error, 'the failure is recorded alongside it');
        $this->assertSame(1, DB::table('partnerships_statsfiles')->where('jobid', $jobId)->count());
    }
}
