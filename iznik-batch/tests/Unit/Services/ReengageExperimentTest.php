<?php

namespace Tests\Unit\Services;

use App\Services\ReengageService;
use App\Support\ExperimentBucket;
use Tests\TestCase;

class ReengageExperimentTest extends TestCase
{
    public function test_bucket_is_deterministic_and_stable_per_user(): void
    {
        $a = ExperimentBucket::bucket(12345, 'reengage-v1');
        $b = ExperimentBucket::bucket(12345, 'reengage-v1');
        $this->assertSame($a, $b, 'Same user+experiment must always yield the same bucket');
        $this->assertGreaterThanOrEqual(0, $a);
        $this->assertLessThan(100, $a);
    }

    public function test_bucket_is_salted_by_experiment_name(): void
    {
        // Different experiment salts should (almost always) decorrelate the same user.
        $differ = 0;
        for ($u = 1; $u <= 200; $u++) {
            if (ExperimentBucket::bucket($u, 'exp-one') !== ExperimentBucket::bucket($u, 'exp-two')) {
                $differ++;
            }
        }
        $this->assertGreaterThan(150, $differ, 'Experiment salt should decorrelate arms across experiments');
    }

    public function test_arm_resolution_covers_configured_ranges(): void
    {
        $arms = [
            'control' => ['from' => 0, 'to' => 19],
            'a' => ['from' => 20, 'to' => 59],
            'b' => ['from' => 60, 'to' => 99],
        ];

        $seen = [];
        for ($u = 1; $u <= 3000; $u++) {
            $r = ExperimentBucket::resolveArm($u, 'reengage-v1:arm', $arms);
            $this->assertContains($r['arm'], ['control', 'a', 'b']);
            $seen[$r['arm']] = ($seen[$r['arm']] ?? 0) + 1;
        }
        // All three arms should be populated, control ~half the size of a/b.
        $this->assertArrayHasKey('control', $seen);
        $this->assertArrayHasKey('a', $seen);
        $this->assertArrayHasKey('b', $seen);
        $this->assertGreaterThan(0, $seen['control']);
    }

    public function test_subject_matches_each_tip_day(): void
    {
        $service = new ReengageService();

        $this->assertStringContainsString('Welcome to Freegle', $service->subjectFor(1, []));
        $this->assertStringContainsString('nobody wants', $service->subjectFor(2, []));
        $this->assertStringContainsString('Just ask', $service->subjectFor(3, []));
        $this->assertStringContainsString('search Freegle', $service->subjectFor(4, []));
        $this->assertStringContainsString('freegler now', $service->subjectFor(5, []));
    }

    public function test_subjects_are_distinct_per_day(): void
    {
        $service = new ReengageService();
        $subjects = array_map(fn ($d) => $service->subjectFor($d, []), range(1, 5));
        $this->assertCount(5, array_unique($subjects), 'Each day should have its own subject line');
    }
}
