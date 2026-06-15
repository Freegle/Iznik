<?php

namespace Tests\Unit\Models;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsSpatialIndex;
use Tests\TestCase;

class JobModelTest extends TestCase
{
    use SeedsSpatialIndex;

    /** @var int[] job ids seeded into the spatial index, removed in tearDown. */
    private array $seededJobIds = [];

    protected function tearDown(): void
    {
        if ($this->seededJobIds) {
            $this->removeSpatial('jobs', $this->seededJobIds);
            $this->seededJobIds = [];
        }
        parent::tearDown();
    }

    /**
     * Insert a job row AND seed it into the spatial "jobs" index so KNN returns
     * it. Jobs are polygons in the index (FindNearestPolygon skips nil-WKB
     * points), so we seed a small box around the point. Defaults to the standard
     * London test coords and a cpc above the floor; override via $attrs.
     */
    private function seedJob(array $attrs = [], float $lat = 51.5074, float $lng = -0.1278): int
    {
        $srid = (int) config('freegle.srid', 3857);
        $attrs += [
            'location' => 'London',
            'company' => 'Test',
            'city' => 'London',
            'url' => 'https://example.com',
            'cpc' => 0.20,
            'clickability' => 1,
            'visible' => 1,
        ];
        $attrs['geometry'] = DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid));

        $id = DB::table('jobs')->insertGetId($attrs);

        $d = 0.0005;
        $this->seedSpatial('jobs', $id, sprintf(
            'POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
            $lng - $d, $lat - $d,
            $lng + $d, $lat - $d,
            $lng + $d, $lat + $d,
            $lng - $d, $lat + $d,
            $lng - $d, $lat - $d
        ));
        $this->seededJobIds[] = $id;

        return $id;
    }

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
        // Matches the spatial "jobs" dataset floor and the public jobs page.
        $this->assertEquals(0.10, Job::MINIMUM_CPC);
    }

    public function test_job_model_is_not_guarded_except_id(): void
    {
        $job = new Job();
        $this->assertEquals(['id'], $job->getGuarded());
    }

    public function test_near_location_returns_collection(): void
    {
        // Returns a collection even with nothing seeded for this location.
        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_near_location_returns_empty_for_no_jobs(): void
    {
        DB::table('jobs')->delete();

        // Nothing in the test DB, so even if the index returns ids the by-id
        // enrich finds nothing.
        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertTrue($result->isEmpty());
    }

    public function test_near_location_respects_limit(): void
    {
        DB::table('jobs')->delete();

        // Five eligible jobs at the search point.
        for ($i = 0; $i < 5; $i++) {
            $this->seedJob(['title' => 'Test Job ' . $i]);
        }

        $result = Job::nearLocation(51.5074, -0.1278, 3);

        $this->assertCount(3, $result);
    }

    public function test_near_location_filters_by_minimum_cpc(): void
    {
        DB::table('jobs')->delete();

        $this->seedJob(['title' => 'Low CPC Job', 'cpc' => 0.05]);  // below 0.10
        $this->seedJob(['title' => 'Good CPC Job', 'cpc' => 0.20]);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertCount(1, $result);
        $this->assertEquals('Good CPC Job', $result->first()->title);
    }

    public function test_near_location_filters_by_visibility(): void
    {
        DB::table('jobs')->delete();

        $this->seedJob(['title' => 'Visible Job', 'visible' => 1]);
        $this->seedJob(['title' => 'Hidden Job', 'visible' => 0]);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertCount(1, $result);
        $this->assertEquals('Visible Job', $result->first()->title);
    }

    public function test_near_location_varies_picks_within_pool(): void
    {
        // With more eligible jobs than the limit, nearLocation shuffles within a
        // candidate pool (limit * VARIETY_POOL_MULTIPLIER) so consecutive sends
        // don't always show the same ads. The pool is now the nearest jobs
        // (matching the web view's KNN selection), not the top-CPC ones, so we
        // assert the variety invariants only: every result is the right size,
        // and repeated calls surface more than `limit` distinct jobs.
        DB::table('jobs')->delete();

        $limit = 4;
        $total = $limit * Job::VARIETY_POOL_MULTIPLIER + 3; // 15
        for ($i = 0; $i < $total; $i++) {
            $this->seedJob(['title' => 'Job rank ' . $i, 'cpc' => round(1.00 - ($i * 0.01), 4)]);
        }

        $allSeen = [];
        for ($call = 0; $call < 8; $call++) {
            $result = Job::nearLocation(51.5074, -0.1278, $limit);
            $this->assertCount($limit, $result, 'Result count must always equal limit');
            foreach ($result as $job) {
                $allSeen[$job->id] = true;
            }
        }

        $this->assertGreaterThan(
            $limit,
            count($allSeen),
            'Repeated calls should surface more than $limit unique jobs from the pool'
        );
    }

    public function test_near_location_orders_by_cpc_desc(): void
    {
        DB::table('jobs')->delete();

        // All above the 0.10 floor; fewer than the default limit so there is no
        // variety shuffle and the CPC-desc order is preserved.
        $this->seedJob(['title' => 'Low CPC', 'cpc' => 0.10]);
        $this->seedJob(['title' => 'High CPC', 'cpc' => 0.30]);
        $this->seedJob(['title' => 'Medium CPC', 'cpc' => 0.20]);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertEquals('High CPC', $result[0]->title);
        $this->assertEquals('Medium CPC', $result[1]->title);
        $this->assertEquals('Low CPC', $result[2]->title);
    }

    public function test_near_location_title_cases_lowercase_location(): void
    {
        // The jobs feed stores location names lowercase ("stoke-on-trent");
        // nearLocation title-cases them so emails read naturally.
        DB::table('jobs')->delete();

        $this->seedJob(['title' => 'Test Job', 'location' => 'stoke-on-trent']);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertCount(1, $result);
        $this->assertEquals('Stoke-On-Trent', $result->first()->location);
    }

    public function test_near_location_adds_placeholder_image_for_jobs_without_ai_images(): void
    {
        // With no matching ai_images row, image_url falls back to briefcase.png.
        DB::table('jobs')->delete();
        DB::table('ai_images')->delete();

        $this->seedJob(['title' => 'Test Job']);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertCount(1, $result);
        $job = $result->first();
        $this->assertNotNull($job->image_url);
        $this->assertStringContainsString('briefcase.png', $job->image_url);
    }

    public function test_near_location_uses_ai_image_when_available(): void
    {
        DB::table('jobs')->delete();
        DB::table('ai_images')->delete();

        $jobTitle = 'Software Developer';
        $canonicalTitle = strtolower($jobTitle);

        $this->seedJob(['title' => $jobTitle, 'canonical_title' => $canonicalTitle]);

        DB::table('ai_images')->insert([
            'name' => $canonicalTitle,
            'externaluid' => 'freegletusd-abc123',
        ]);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertCount(1, $result);
        $job = $result->first();
        $this->assertNotNull($job->image_url);
        $this->assertStringContainsString('abc123', $job->image_url);
    }

    public function test_build_image_url_returns_null_for_null_externaluid(): void
    {
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

    public function test_near_location_finds_jobs_within_search_reach(): void
    {
        // A job ~5.5km north is still within the spatial KNN's search reach.
        DB::table('jobs')->delete();

        $this->seedJob(['title' => 'Distant Job', 'location' => 'North London'], 51.5074 + 0.05, -0.1278);

        $result = Job::nearLocation(51.5074, -0.1278);

        $this->assertCount(1, $result);
    }
}
