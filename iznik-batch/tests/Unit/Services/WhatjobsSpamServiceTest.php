<?php

namespace Tests\Unit\Services;

use App\Services\WhatjobsSpamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatjobsSpamServiceTest extends TestCase
{
    protected WhatjobsSpamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WhatjobsSpamService();
        DB::table('jobs')->delete();
    }

    private function insertJob(array $overrides = []): void
    {
        $srid = config('freegle.srid', 3857);
        $defaults = [
            'title' => 'Test Job',
            'location' => 'London ' . uniqid(),
            'bodyhash' => null,
            'visible' => 0,
            'geometry' => DB::raw("ST_GeomFromText('POINT(-0.1278 51.5074)', $srid)"),
        ];
        DB::table('jobs')->insert(array_merge($defaults, $overrides));
    }

    public function test_empty_input_returns_zero(): void
    {
        $result = $this->service->deleteSpammyJobs();

        $this->assertEquals(0, $result['deleted']);
        $this->assertEquals(0, $result['spam_hashes']);
    }

    public function test_below_threshold_not_deleted(): void
    {
        // 50 jobs with same bodyhash — exactly at threshold, not over
        for ($i = 0; $i < 50; $i++) {
            $this->insertJob(['bodyhash' => 'aabbcc', 'title' => 'Spam Job']);
        }

        $result = $this->service->deleteSpammyJobs();

        $this->assertEquals(0, $result['deleted']);
        $this->assertEquals(50, DB::table('jobs')->where('bodyhash', 'aabbcc')->count());
    }

    public function test_above_threshold_all_deleted(): void
    {
        Log::spy();

        // 51 jobs with same bodyhash — over threshold
        for ($i = 0; $i < 51; $i++) {
            $this->insertJob(['bodyhash' => 'spamhash1', 'title' => 'Spam Title']);
        }
        // 3 jobs with different bodyhash — should not be deleted
        for ($i = 0; $i < 3; $i++) {
            $this->insertJob(['bodyhash' => 'safehash', 'title' => 'Safe Job']);
        }

        $result = $this->service->deleteSpammyJobs();

        $this->assertEquals(51, $result['deleted']);
        $this->assertEquals(1, $result['spam_hashes']);
        $this->assertEquals(0, DB::table('jobs')->where('bodyhash', 'spamhash1')->count());
        $this->assertEquals(3, DB::table('jobs')->where('bodyhash', 'safehash')->count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg) => str_contains($msg, 'Spam Title') && str_contains($msg, '51'))
            ->once();
    }

    public function test_null_bodyhash_not_deleted(): void
    {
        // 51 jobs with NULL bodyhash — should not be affected
        for ($i = 0; $i < 51; $i++) {
            $this->insertJob(['bodyhash' => null]);
        }

        $result = $this->service->deleteSpammyJobs();

        $this->assertEquals(0, $result['deleted']);
        $this->assertEquals(51, DB::table('jobs')->count());
    }

    public function test_multiple_spam_hashes_all_deleted(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->insertJob(['bodyhash' => 'hash_a', 'title' => 'Spam A']);
        }
        for ($i = 0; $i < 60; $i++) {
            $this->insertJob(['bodyhash' => 'hash_b', 'title' => 'Spam B']);
        }

        $result = $this->service->deleteSpammyJobs();

        $this->assertEquals(111, $result['deleted']);
        $this->assertEquals(2, $result['spam_hashes']);
        $this->assertEquals(0, DB::table('jobs')->where('bodyhash', 'hash_a')->count());
        $this->assertEquals(0, DB::table('jobs')->where('bodyhash', 'hash_b')->count());
    }
}
