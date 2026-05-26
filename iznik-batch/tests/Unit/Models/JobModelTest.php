<?php

namespace Tests\Unit\Models;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobModelTest extends TestCase
{
    public function test_job_model_has_correct_table(): void
    {
        $job = new Job();
        $this->assertEquals('jobs', $job->getTable());
    }

    public function test_job_model_has_no_timestamps(): void
    {
        $job = new Job();
        $this->assertFalse($job->timestamps);
    }

    public function test_job_model_casts(): void
    {
        $job = new Job();
        $casts = $job->getCasts();

        $this->assertArrayHasKey('posted_at', $casts);
        $this->assertArrayHasKey('seenat', $casts);
        $this->assertArrayHasKey('cpc', $casts);
        $this->assertEquals('datetime', $casts['posted_at']);
        $this->assertEquals('datetime', $casts['seenat']);
        $this->assertEquals('decimal:4', $casts['cpc']);
    }

    public function test_minimum_cpc_constant(): void
    {
        $this->assertEquals(0.02, Job::MINIMUM_CPC);
    }

    public function test_job_model_is_not_guarded_except_id(): void
    {
        $job = new Job();
        $this->assertEquals(['id'], $job->getGuarded());
    }

    public function test_near_location_returns_collection(): void
    {
        // Test that nearLocation returns a collection even with no jobs.
        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_near_location_returns_empty_for_no_jobs(): void
    {
        // Ensure table is empty for this test.
        DB::table('jobs')->delete();

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertTrue($result->isEmpty());
    }

    public function test_near_location_respects_limit(): void
    {
        DB::table('jobs')->delete();
        $srid = config('freegle.srid', 3857);

        // Insert 5 visible jobs with good CPC near London.
        for ($i = 0; $i < 5; $i++) {
            $lat = 51.5074 + ($i * 0.001);
            $lng = -0.1278 + ($i * 0.001);
            DB::table('jobs')->insert([
                'title' => 'Test Job ' . $i,
                'location' => 'London',
                'company' => 'Test Company',
                'city' => 'London',
                'url' => 'https://example.com/job/' . $i,
                'cpc' => 0.05,
                'visible' => 1,
                'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
            ]);
        }

        // Request 3 jobs - should get 3.
        $result = Job::nearLocation(51.5074, -0.1278, 3);

        $this->assertCount(3, $result);
    }

    public function test_near_location_filters_by_minimum_cpc(): void
    {
        DB::table('jobs')->delete();
        $srid = config('freegle.srid', 3857);
        $lat = 51.5074;
        $lng = -0.1278;

        // Insert job with CPC below minimum.
        DB::table('jobs')->insert([
            'title' => 'Low CPC Job',
            'location' => 'London',
            'company' => 'Test Company',
            'city' => 'London',
            'url' => 'https://example.com/job/low',
            'cpc' => 0.01,  // Below MINIMUM_CPC of 0.02.
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        // Insert job with CPC at minimum.
        DB::table('jobs')->insert([
            'title' => 'Good CPC Job',
            'location' => 'London',
            'company' => 'Test Company',
            'city' => 'London',
            'url' => 'https://example.com/job/good',
            'cpc' => 0.02,  // At MINIMUM_CPC.
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        $result = Job::nearLocation($lat, $lng);

        // Should only get the one with good CPC.
        $this->assertCount(1, $result);
        $this->assertEquals('Good CPC Job', $result->first()->title);
    }

    public function test_near_location_filters_by_visibility(): void
    {
        DB::table('jobs')->delete();
        $srid = config('freegle.srid', 3857);
        $lat = 51.5074;
        $lng = -0.1278;

        // Insert visible job.
        DB::table('jobs')->insert([
            'title' => 'Visible Job',
            'location' => 'London',
            'company' => 'Test Company',
            'city' => 'London',
            'url' => 'https://example.com/job/visible',
            'cpc' => 0.05,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        // Insert hidden job.
        DB::table('jobs')->insert([
            'title' => 'Hidden Job',
            'location' => 'London',
            'company' => 'Test Company',
            'city' => 'London',
            'url' => 'https://example.com/job/hidden',
            'cpc' => 0.05,
            'visible' => 0,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        $result = Job::nearLocation($lat, $lng);

        // Should only get the visible one.
        $this->assertCount(1, $result);
        $this->assertEquals('Visible Job', $result->first()->title);
    }

    public function test_near_location_varies_picks_from_top_cpc_pool(): void
    {
        // When there are more jobs available than the limit, nearLocation
        // shuffles within a candidate pool sized to limit * VARIETY_POOL_MULTIPLIER
        // so consecutive immediate-mode digests don't always show the same
        // ads. Verify (a) the result size stays at limit and (b) every
        // returned job comes from the top (limit*M) by CPC — anything
        // below that pool size must never be picked.
        DB::table('jobs')->delete();
        $srid = config('freegle.srid', 3857);
        $lat = 51.5074;
        $lng = -0.1278;

        $limit = 4;
        $poolSize = $limit * Job::VARIETY_POOL_MULTIPLIER; // 12
        $totalJobs = $poolSize + 5; // 17 — five jobs below the pool that must never appear.

        // CPCs strictly descending so we know exactly which IDs belong in
        // the eligible top-12 pool versus the always-excluded tail.
        $insertedIdsByRank = [];
        for ($i = 0; $i < $totalJobs; $i++) {
            $cpc = round(1.00 - ($i * 0.01), 4); // 1.00, 0.99, ... well above MINIMUM_CPC.
            DB::table('jobs')->insert([
                'title' => 'Job rank ' . $i,
                'location' => 'London',
                'company' => 'Test',
                'city' => 'London',
                'url' => 'https://example.com/job/' . $i,
                'cpc' => $cpc,
                'visible' => 1,
                'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
            ]);
            $insertedIdsByRank[] = DB::getPdo()->lastInsertId();
        }

        $eligibleIds = array_slice($insertedIdsByRank, 0, $poolSize);
        $excludedIds = array_slice($insertedIdsByRank, $poolSize);

        // Run the query several times; track which IDs ever appear.
        $allSeen = [];
        for ($call = 0; $call < 8; $call++) {
            $result = Job::nearLocation($lat, $lng, $limit);
            $this->assertCount($limit, $result, 'Result count must always equal limit');
            foreach ($result as $job) {
                $this->assertNotContains(
                    (string) $job->id,
                    $excludedIds,
                    'No job below the variety pool may ever be selected'
                );
                $allSeen[$job->id] = true;
            }
        }

        // With limit=4 picked from a pool of 12 the probability of seeing
        // only 4 IDs across 8 calls is vanishingly small (~1 in 1e6), so
        // requiring strictly more than $limit unique IDs proves the
        // shuffle is actually doing something.
        $this->assertGreaterThan(
            $limit,
            count($allSeen),
            'Repeated calls should surface more than $limit unique jobs from the pool'
        );
    }

    public function test_near_location_orders_by_cpc_desc(): void
    {
        DB::table('jobs')->delete();
        $srid = config('freegle.srid', 3857);
        $lat = 51.5074;
        $lng = -0.1278;

        // Insert jobs with different CPCs.
        DB::table('jobs')->insert([
            'title' => 'Low CPC',
            'location' => 'London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com/1',
            'cpc' => 0.03,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        DB::table('jobs')->insert([
            'title' => 'High CPC',
            'location' => 'London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com/2',
            'cpc' => 0.10,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        DB::table('jobs')->insert([
            'title' => 'Medium CPC',
            'location' => 'London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com/3',
            'cpc' => 0.05,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        $result = Job::nearLocation($lat, $lng);

        // Should be ordered high to low CPC.
        $this->assertEquals('High CPC', $result[0]->title);
        $this->assertEquals('Medium CPC', $result[1]->title);
        $this->assertEquals('Low CPC', $result[2]->title);
    }

    public function test_near_location_adds_placeholder_image_for_jobs_without_ai_images(): void
    {
        // When a job has no matching ai_images row, image_url falls back to
        // briefcase.png — a friendly cartoon briefcase rendered in the same
        // AI-image house style as the per-title illustrations so rows look
        // consistent alongside ones with a real AI image.
        DB::table('jobs')->delete();
        DB::table('ai_images')->delete();
        $srid = config('freegle.srid', 3857);
        $lat = 51.5074;
        $lng = -0.1278;

        DB::table('jobs')->insert([
            'title' => 'Test Job',
            'location' => 'London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com',
            'cpc' => 0.05,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        $result = Job::nearLocation($lat, $lng);

        $this->assertCount(1, $result);
        $job = $result->first();
        $this->assertNotNull($job->image_url);
        $this->assertStringContainsString('briefcase.png', $job->image_url);
    }

    public function test_near_location_uses_ai_image_when_available(): void
    {
        DB::table('jobs')->delete();
        DB::table('ai_images')->delete();
        $srid = config('freegle.srid', 3857);
        $lat = 51.5074;
        $lng = -0.1278;

        $jobTitle = 'Software Developer';

        $canonicalTitle = strtolower($jobTitle);

        DB::table('jobs')->insert([
            'title' => $jobTitle,
            'canonical_title' => $canonicalTitle,
            'location' => 'London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com',
            'cpc' => 0.05,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)"),
        ]);

        // Add AI image for this job's canonical title.
        DB::table('ai_images')->insert([
            'name' => $canonicalTitle,
            'externaluid' => 'freegletusd-abc123',
        ]);

        $result = Job::nearLocation($lat, $lng);

        $this->assertCount(1, $result);
        $job = $result->first();
        $this->assertNotNull($job->image_url);
        // Should contain the file ID from the externaluid.
        $this->assertStringContainsString('abc123', $job->image_url);
    }

    public function test_build_image_url_returns_null_for_null_externaluid(): void
    {
        // Use reflection to test protected method.
        $reflection = new \ReflectionClass(Job::class);
        $method = $reflection->getMethod('buildImageUrl');
        $method->setAccessible(true);

        $result = $method->invoke(null, null);

        $this->assertNull($result);
    }

    public function test_build_image_url_returns_null_for_invalid_format(): void
    {
        $reflection = new \ReflectionClass(Job::class);
        $method = $reflection->getMethod('buildImageUrl');
        $method->setAccessible(true);

        // Missing freegletusd- prefix.
        $result = $method->invoke(null, 'invalid-format');

        $this->assertNull($result);
    }

    public function test_build_image_url_extracts_file_id_correctly(): void
    {
        $reflection = new \ReflectionClass(Job::class);
        $method = $reflection->getMethod('buildImageUrl');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'freegletusd-myfileid123');

        $this->assertNotNull($result);
        $this->assertStringContainsString('myfileid123', $result);
    }

    public function test_build_image_url_includes_delivery_service_when_configured(): void
    {
        config(['freegle.delivery.base_url' => 'https://delivery.example.com']);

        $reflection = new \ReflectionClass(Job::class);
        $method = $reflection->getMethod('buildImageUrl');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'freegletusd-testid');

        $this->assertStringContainsString('delivery.example.com', $result);
        $this->assertStringContainsString('w=50', $result);
    }

    public function test_near_location_expands_search_area_to_find_jobs(): void
    {
        DB::table('jobs')->delete();
        $srid = config('freegle.srid', 3857);

        // Place job slightly outside initial search box but within expansion range.
        $jobLat = 51.5074 + 0.05;  // About 5.5km north.
        $jobLng = -0.1278;

        DB::table('jobs')->insert([
            'title' => 'Distant Job',
            'location' => 'North London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com',
            'cpc' => 0.05,
            'visible' => 1,
            'geometry' => DB::raw("ST_GeomFromText('POINT($jobLng $jobLat)', $srid)"),
        ]);

        // Search from a point where initial box won't contain the job.
        $result = Job::nearLocation(51.5074, -0.1278);

        // Should find the job through box expansion.
        $this->assertCount(1, $result);
    }
}
