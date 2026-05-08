<?php

namespace Tests\Unit\Services;

use App\Services\JobIllustrationsService;
use App\Services\PollinationsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobIllustrationsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('ai_images')->delete();
    }

    private function makeService(array $fetchResult = null, bool $skipAll = false): JobIllustrationsService
    {
        $mock = $this->createMock(PollinationsService::class);
        $mock->method('shouldSkipItem')->willReturn($skipAll);
        $mock->method('recordFailure')->willReturn(false);
        $mock->method('fetchBatch')->willReturn($fetchResult ?? ['results' => [], 'failed' => []]);
        $mock->method('buildJobPrompt')->willReturnCallback(fn ($obj) => "prompt for $obj");
        // Actually insert into ai_images so the loop terminates.
        $mock->method('uploadImageAndCache')->willReturnCallback(function (string $name) {
            DB::table('ai_images')->insertOrIgnore(['name' => $name, 'externaluid' => 'freegletusd-jobtest']);

            return 'freegletusd-jobtest';
        });

        return new JobIllustrationsService($mock);
    }

    public function test_processes_missing_canonical_job(): void
    {
        // Pre-insert all canonical jobs except 'Accountant'.
        $allJobs = array_keys(JobIllustrationsService::CANONICAL_JOBS);
        $jobsExceptFirst = array_slice($allJobs, 1);
        foreach ($jobsExceptFirst as $title) {
            DB::table('ai_images')->insert(['name' => $title, 'externaluid' => 'freegletusd-existing']);
        }

        $fakeFetch = [
            'results' => [
                ['name' => 'Accountant', 'data' => 'fakeimagedata', 'hash' => 'hash001', 'msgid' => null],
            ],
            'failed' => [],
        ];

        $service = $this->makeService($fakeFetch);
        $result = $service->processIllustrations();

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['remaining']);
    }

    public function test_skips_already_cached_jobs(): void
    {
        // Pre-insert all canonical jobs.
        foreach (array_keys(JobIllustrationsService::CANONICAL_JOBS) as $title) {
            DB::table('ai_images')->insert(['name' => $title, 'externaluid' => 'freegletusd-existing']);
        }

        $mock = $this->createMock(PollinationsService::class);
        $mock->expects($this->never())->method('fetchBatch');

        $service = new JobIllustrationsService($mock);
        $result = $service->processIllustrations();

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['remaining']);
    }

    public function test_returns_remaining_count_after_rate_limit(): void
    {
        // No pre-existing images.
        $mock = $this->createMock(PollinationsService::class);
        $mock->method('shouldSkipItem')->willReturn(false);
        $mock->method('recordFailure')->willReturn(false);
        $mock->method('fetchBatch')->willReturn(false); // rate-limited
        $mock->method('buildJobPrompt')->willReturn('prompt');

        $service = new JobIllustrationsService($mock);
        $result = $service->processIllustrations();

        $this->assertEquals(0, $result['processed']);
        $this->assertGreaterThan(0, $result['remaining']);
    }
}
