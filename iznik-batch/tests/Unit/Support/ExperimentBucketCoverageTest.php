<?php

namespace Tests\Unit\Support;

use App\Support\ExperimentBucket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExperimentBucket. Pure logic: no DB, no filesystem, no external services.
 */
class ExperimentBucketCoverageTest extends TestCase
{
    // -------------------------------------------------------------------------
    // bucket
    // -------------------------------------------------------------------------

    public static function pinnedProvider(): array
    {
        // Expected values are crc32("<id>|<experiment>[|<salt>]") % buckets, which the browser,
        // this class and the Go API must all agree on.
        return [
            'user 1, welcome-ish, 100' => [1, 'a', 100, '', 97],
            'user 1, 10 buckets' => [1, 'a', 10, '', 7],
            'user 1, 2 buckets' => [1, 'a', 2, '', 1],
            'user 1, salted' => [1, 'a', 100, 's1', 48],
            'user 12345, 100' => [12345, 'welcome', 100, '', 51],
            'user 12345, 10' => [12345, 'welcome', 10, '', 1],
            'user 12345, salted' => [12345, 'welcome', 100, 's1', 74],
        ];
    }

    #[DataProvider('pinnedProvider')]
    public function test_bucket_pinned_values(int $userId, string $experiment, int $buckets, string $salt, int $expected): void
    {
        $this->assertSame($expected, ExperimentBucket::bucket($userId, $experiment, $buckets, $salt));

        $text = $userId . '|' . $experiment . ($salt !== '' ? '|' . $salt : '');
        $this->assertSame(crc32($text) % $buckets, ExperimentBucket::bucket($userId, $experiment, $buckets, $salt));
    }

    public function test_bucket_defaults_to_100_buckets_and_no_salt(): void
    {
        $this->assertSame(
            ExperimentBucket::bucket(77, 'x', 100, ''),
            ExperimentBucket::bucket(77, 'x')
        );
    }

    public function test_bucket_is_deterministic(): void
    {
        $first = ExperimentBucket::bucket(4242, 'exp');

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, ExperimentBucket::bucket(4242, 'exp'));
        }
    }

    public static function bucketCountProvider(): array
    {
        return [[100], [10], [2], [1], [7]];
    }

    #[DataProvider('bucketCountProvider')]
    public function test_bucket_in_range(int $buckets): void
    {
        foreach ([0, 1, 2, 99, 100, 12345, 987654, PHP_INT_MAX] as $userId) {
            $b = ExperimentBucket::bucket($userId, 'range-test', $buckets);
            $this->assertGreaterThanOrEqual(0, $b);
            $this->assertLessThanOrEqual($buckets - 1, $b);
        }
    }

    public function test_bucket_single_bucket_is_always_zero(): void
    {
        $this->assertSame(0, ExperimentBucket::bucket(5, 'e', 1));
    }

    public function test_experiment_name_salts_the_input(): void
    {
        // Across many users two experiments must not move in lockstep.
        $differ = 0;
        for ($u = 1; $u <= 200; $u++) {
            if (ExperimentBucket::bucket($u, 'exp-one') !== ExperimentBucket::bucket($u, 'exp-two')) {
                $differ++;
            }
        }
        $this->assertGreaterThan(150, $differ);
    }

    public function test_explicit_salt_changes_bucket(): void
    {
        $differ = 0;
        for ($u = 1; $u <= 200; $u++) {
            if (ExperimentBucket::bucket($u, 'exp') !== ExperimentBucket::bucket($u, 'exp', 100, 'rerun')) {
                $differ++;
            }
        }
        $this->assertGreaterThan(150, $differ);
        $this->assertNotSame(
            ExperimentBucket::bucket(1, 'a', 100),
            ExperimentBucket::bucket(1, 'a', 100, 's1')
        );
    }

    public function test_distribution_is_roughly_uniform(): void
    {
        $counts = array_fill(0, 10, 0);
        for ($u = 1; $u <= 5000; $u++) {
            $counts[ExperimentBucket::bucket($u, 'uniform', 10)]++;
        }
        foreach ($counts as $c) {
            $this->assertGreaterThan(350, $c);
            $this->assertLessThan(650, $c);
        }
    }

    // -------------------------------------------------------------------------
    // armIn
    // -------------------------------------------------------------------------

    private const USER = 12345;
    private const EXP = 'welcome';

    private function userBucket(string $salt = ''): int
    {
        return ExperimentBucket::bucket(self::USER, self::EXP, 100, $salt);
    }

    public function test_arm_in_matches_range_holding_bucket(): void
    {
        $b = $this->userBucket();
        $ranges = ['other' => [($b + 1) % 100 === 0 ? 0 : $b + 1, 99], 'mine' => [$b, $b]];
        if ($b === 99) {
            $ranges['other'] = [100, 100];
        }

        $this->assertSame('mine', ExperimentBucket::armIn(self::USER, self::EXP, $ranges));
    }

    public function test_arm_in_bounds_are_inclusive(): void
    {
        $b = $this->userBucket();

        $this->assertSame('lo', ExperimentBucket::armIn(self::USER, self::EXP, ['lo' => [$b, 100]]));
        $this->assertSame('hi', ExperimentBucket::armIn(self::USER, self::EXP, ['hi' => [-1, $b]]));
        $this->assertNull(ExperimentBucket::armIn(self::USER, self::EXP, ['x' => [$b + 1, 200]]));
        $this->assertNull(ExperimentBucket::armIn(self::USER, self::EXP, ['x' => [-5, $b - 1]]));
    }

    public function test_arm_in_first_matching_arm_wins(): void
    {
        $b = $this->userBucket();
        $ranges = ['first' => [0, 99], 'second' => [$b, $b]];

        $this->assertSame('first', ExperimentBucket::armIn(self::USER, self::EXP, $ranges));
    }

    public function test_arm_in_from_above_to_holds_nobody(): void
    {
        $b = $this->userBucket();
        $ranges = ['none' => [$b + 1, $b - 1], 'all' => [0, 99]];

        $this->assertSame('all', ExperimentBucket::armIn(self::USER, self::EXP, $ranges));
        $this->assertNull(ExperimentBucket::armIn(self::USER, self::EXP, ['none' => [99, 0]]));
    }

    public static function malformedRangeProvider(): array
    {
        return [
            'not an array (string)' => ['0-99'],
            'not an array (int)' => [5],
            'not an array (null)' => [null],
            'empty array' => [[]],
            'one element' => [[0]],
            'three elements' => [[0, 50, 99]],
        ];
    }

    #[DataProvider('malformedRangeProvider')]
    public function test_arm_in_malformed_range_is_skipped(mixed $bad): void
    {
        $ranges = ['bad' => $bad, 'good' => [0, 99]];

        $this->assertSame('good', ExperimentBucket::armIn(self::USER, self::EXP, $ranges));
        $this->assertNull(ExperimentBucket::armIn(self::USER, self::EXP, ['bad' => $bad]));
    }

    public function test_arm_in_accepts_associative_two_element_range(): void
    {
        // array_values() means keys do not matter, only order.
        $this->assertSame('a', ExperimentBucket::armIn(self::USER, self::EXP, ['a' => ['from' => 0, 'to' => 99]]));
    }

    public function test_arm_in_no_arms_returns_null(): void
    {
        $this->assertNull(ExperimentBucket::armIn(self::USER, self::EXP, []));
    }

    public function test_arm_in_integer_arm_keys_are_returned_as_strings(): void
    {
        $this->assertSame('7', ExperimentBucket::armIn(self::USER, self::EXP, [7 => [0, 99]]));
    }

    public function test_arm_in_salt_passthrough(): void
    {
        // Find a salt that moves the user to a different bucket, then show armIn follows it.
        $unsalted = $this->userBucket();
        $salt = 'rerun';
        for ($i = 0; $this->userBucket($salt) === $unsalted && $i < 20; $i++) {
            $salt = 'rerun' . $i;
        }
        $salted = $this->userBucket($salt);
        $this->assertNotSame($unsalted, $salted);

        $ranges = ['at-unsalted' => [$unsalted, $unsalted], 'at-salted' => [$salted, $salted]];

        $this->assertSame('at-unsalted', ExperimentBucket::armIn(self::USER, self::EXP, $ranges));
        $this->assertSame('at-salted', ExperimentBucket::armIn(self::USER, self::EXP, $ranges, $salt));
    }

    // -------------------------------------------------------------------------
    // resolveArm
    // -------------------------------------------------------------------------

    public function test_resolve_arm_found(): void
    {
        $b = $this->userBucket();
        $arms = ['control' => ['from' => 0, 'to' => $b - 1], 'treat' => ['from' => $b, 'to' => 99]];

        $this->assertSame(['arm' => 'treat', 'bucket' => $b], ExperimentBucket::resolveArm(self::USER, self::EXP, $arms));
    }

    public function test_resolve_arm_bounds_inclusive(): void
    {
        $b = $this->userBucket();

        $this->assertSame('x', ExperimentBucket::resolveArm(self::USER, self::EXP, ['x' => ['from' => $b, 'to' => $b]])['arm']);
    }

    public function test_resolve_arm_uncovered_bucket_returns_empty_arm(): void
    {
        $b = $this->userBucket();
        $arms = ['a' => ['from' => $b + 1, 'to' => $b + 50]];

        $this->assertSame(['arm' => '', 'bucket' => $b], ExperimentBucket::resolveArm(self::USER, self::EXP, $arms));
    }

    public function test_resolve_arm_empty_arms(): void
    {
        $this->assertSame(
            ['arm' => '', 'bucket' => $this->userBucket()],
            ExperimentBucket::resolveArm(self::USER, self::EXP, [])
        );
    }

    public static function missingKeysProvider(): array
    {
        return [
            'neither key' => [[]],
            'missing to defaults to -1' => [['from' => 0]],
        ];
    }

    #[DataProvider('missingKeysProvider')]
    public function test_resolve_arm_missing_keys_never_match(array $range): void
    {
        $this->assertSame('', ExperimentBucket::resolveArm(self::USER, self::EXP, ['x' => $range])['arm']);
    }

    public function test_resolve_arm_missing_from_defaults_to_zero(): void
    {
        $this->assertSame('x', ExperimentBucket::resolveArm(self::USER, self::EXP, ['x' => ['to' => 99]])['arm']);
    }

    public function test_resolve_arm_string_bounds_are_cast(): void
    {
        $arms = ['x' => ['from' => '0', 'to' => '99']];

        $this->assertSame('x', ExperimentBucket::resolveArm(self::USER, self::EXP, $arms)['arm']);
    }

    public function test_resolve_arm_first_matching_wins_on_overlap(): void
    {
        $b = $this->userBucket();
        $arms = [
            'miss' => ['from' => $b + 1, 'to' => 200],
            'first' => ['from' => 0, 'to' => 99],
            'second' => ['from' => $b, 'to' => $b],
        ];

        $this->assertSame('first', ExperimentBucket::resolveArm(self::USER, self::EXP, $arms)['arm']);
    }

    public function test_resolve_arm_integer_keys_become_strings(): void
    {
        $this->assertSame(['arm' => '3', 'bucket' => $this->userBucket()], ExperimentBucket::resolveArm(
            self::USER,
            self::EXP,
            [3 => ['from' => 0, 'to' => 99]]
        ));
    }

    public function test_resolve_arm_agrees_with_arm_in(): void
    {
        for ($u = 1; $u <= 50; $u++) {
            $resolved = ExperimentBucket::resolveArm($u, 'agree', ['a' => ['from' => 0, 'to' => 49], 'b' => ['from' => 50, 'to' => 99]]);
            $this->assertSame($resolved['arm'], ExperimentBucket::armIn($u, 'agree', ['a' => [0, 49], 'b' => [50, 99]]));
        }
    }
}
