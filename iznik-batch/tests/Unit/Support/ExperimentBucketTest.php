<?php

namespace Tests\Unit\Support;

use App\Support\ExperimentBucket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The batch's half of the shared bucket function. The same vectors are pinned in the browser
 * (iznik-nuxt3/tests/unit/composables/useExperiment.spec.js) and in Go
 * (iznik-server-go/test/ux_serverarm_test.go); a change that moves a bucket in one runtime fails all
 * three.
 */
class ExperimentBucketTest extends TestCase
{
    private const IDS = [1, 2, 3, 5, 12345, 999999];

    /** @return array<string, array{0: string, 1: string, 2: int[]}> */
    public static function vectors(): array
    {
        return [
            'confirm-reply' => ['confirm-reply', '', [44, 73, 77, 55, 1, 38]],
            'digest-subject' => ['digest-subject', '', [87, 77, 72, 88, 29, 68]],
            'digest-subject, salt r2' => ['digest-subject', 'r2', [88, 97, 5, 2, 11, 58]],
        ];
    }

    /** @param int[] $want */
    #[DataProvider('vectors')]
    public function test_the_buckets_match_the_shared_vectors(string $name, string $salt, array $want): void
    {
        $got = array_map(fn ($id) => ExperimentBucket::bucket($id, $name, 100, $salt), self::IDS);

        $this->assertSame($want, $got);
    }

    public function test_the_three_buckets_the_browser_pins_hold_here_too(): void
    {
        $this->assertSame(89, ExperimentBucket::bucket(123, 'exp'));
        $this->assertSame(40, ExperimentBucket::bucket(42, 'give-hint'));
    }

    public function test_an_empty_salt_is_no_salt_and_a_salt_moves_the_bucket(): void
    {
        $this->assertSame(ExperimentBucket::bucket(5, 'digest-subject'), ExperimentBucket::bucket(5, 'digest-subject', 100, ''));
        $this->assertNotSame(ExperimentBucket::bucket(5, 'digest-subject'), ExperimentBucket::bucket(5, 'digest-subject', 100, 'r2'));
    }

    public function test_the_existing_callers_that_pass_three_arguments_are_untouched(): void
    {
        // ReengageService calls bucket($id, $name, $buckets). The salt is the last argument.
        $this->assertSame(crc32('7|reengage-v1') % 20, ExperimentBucket::bucket(7, 'reengage-v1', 20));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: ?string}> */
    public static function edges(): array
    {
        // Member 1 on "confirm-reply" has bucket 44, member 5 has bucket 55 and member 12345 has bucket 1.
        $arms = ['control' => [0, 49], 'items' => [50, 99]];

        return [
            'inside the first arm' => [$arms, 1, 'control'],
            'inside the second arm' => [$arms, 5, 'items'],
            'the bottom of the first range, inclusive' => [['a' => [1, 49]], 12345, 'a'],
            'one below the bottom of the first range' => [['a' => [2, 49]], 12345, null],
            'the top of a range, inclusive' => [['a' => [0, 44]], 1, 'a'],
            'one above the top of a range' => [['a' => [0, 43]], 1, null],
            'a range with from above to holds nobody' => [['empty' => [99, 98], 'a' => [0, 99]], 1, 'a'],
            'an empty range alone holds nobody' => [['empty' => [99, 98]], 1, null],
            'no arms' => [[], 1, null],
            'the first arm that holds it wins' => [['first' => [0, 99], 'second' => [0, 99]], 1, 'first'],
            'a malformed range is skipped' => [['bad' => [0], 'a' => [0, 99]], 1, 'a'],
            'a range that is not a list is skipped' => [['bad' => 'x', 'a' => [0, 99]], 1, 'a'],
        ];
    }

    /** @param array<string, mixed> $ranges */
    #[DataProvider('edges')]
    public function test_arm_in_picks_the_first_inclusive_range_that_holds_the_bucket(array $ranges, int $userId, ?string $want): void
    {
        $this->assertSame($want, ExperimentBucket::armIn($userId, 'confirm-reply', $ranges));
    }

    public function test_arm_in_uses_the_salt(): void
    {
        $arms = ['control' => [0, 49], 'items' => [50, 99]];

        // Member 3 on "digest-subject": bucket 72 without a salt, 5 with the salt r2.
        $this->assertSame('items', ExperimentBucket::armIn(3, 'digest-subject', $arms));
        $this->assertSame('control', ExperimentBucket::armIn(3, 'digest-subject', $arms, 'r2'));
    }

    public function test_arm_in_edges_at_buckets_0_49_50_and_99(): void
    {
        $arms = ['control' => [0, 49], 'items' => [50, 99]];
        $byBucket = [];
        for ($id = 1; $id < 5000 && count($byBucket) < 4; $id++) {
            $b = ExperimentBucket::bucket($id, 'edges');
            if (in_array($b, [0, 49, 50, 99], true) && ! isset($byBucket[$b])) {
                $byBucket[$b] = $id;
            }
        }

        ksort($byBucket);
        $this->assertSame([0, 49, 50, 99], array_keys($byBucket), 'the search found a member for each edge');
        $this->assertSame('control', ExperimentBucket::armIn($byBucket[0], 'edges', $arms));
        $this->assertSame('control', ExperimentBucket::armIn($byBucket[49], 'edges', $arms));
        $this->assertSame('items', ExperimentBucket::armIn($byBucket[50], 'edges', $arms));
        $this->assertSame('items', ExperimentBucket::armIn($byBucket[99], 'edges', $arms));
    }
}
