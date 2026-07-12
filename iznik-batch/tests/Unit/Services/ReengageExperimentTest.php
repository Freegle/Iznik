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

    public function test_subject_uses_singular_for_one_item(): void
    {
        $service = new ReengageService();

        $one = $service->subjectFor(1, ['offerCount' => 1], 'a');
        $this->assertStringContainsString('1 free thing near you', $one);
        $this->assertStringNotContainsString('1 free things', $one);

        $many = $service->subjectFor(1, ['offerCount' => 5], 'a');
        $this->assertStringContainsString('5 free things near you', $many);
    }

    public function test_arm_b_subject_differs_from_arm_a(): void
    {
        $service = new ReengageService();
        $a = $service->subjectFor(1, ['offerCount' => 5], 'a');
        $b = $service->subjectFor(1, ['offerCount' => 5], 'b');
        $this->assertNotSame($a, $b, 'The b arm must ship a different subject line to make the A/B measurable');
    }

    public function test_subject_zero_count_has_no_number(): void
    {
        $service = new ReengageService();
        $s = $service->subjectFor(1, ['offerCount' => 0], 'a');
        $this->assertStringNotContainsString('0 free', $s);
    }
}
