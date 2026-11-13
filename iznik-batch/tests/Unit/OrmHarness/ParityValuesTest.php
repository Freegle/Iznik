<?php

namespace Tests\Unit\OrmHarness;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrmHarness\ParityValues;

/**
 * The normalisation behind Layer 2. Pure, so it needs no database. The Go twin is
 * iznik-server-go/parity/values_test.go and the two agree on every case below.
 */
class ParityValuesTest extends TestCase
{
    public static function sameValues(): array
    {
        return [
            'a boolean and 1' => [true, 1],
            'false and 0' => [false, 0],
            'a float with no fraction' => [5.0, 5],
            'a time in another zone' => [new \DateTimeImmutable('2026-01-02 10:00:00+01:00'), new \DateTimeImmutable('2026-01-02 09:00:00+00:00')],
            'a time written as text' => ['2026-01-02T09:00:00Z', new \DateTimeImmutable('2026-01-02 09:00:00+00:00')],
            'a time with an offset as text' => ['2026-01-02 10:00:00+01', '2026-01-02 09:00:00+00'],
            'a time with no zone is kept as written' => ['2026-01-02 09:00:00', '2026-01-02T09:00:00'],
            'a row object and a row array' => [(object) ['a' => 'x', 'b' => 1], ['a' => 'x', 'b' => true]],
            'a collection and a list' => [collect([1, 2]), [1, 2]],
        ];
    }

    #[DataProvider('sameValues')]
    public function test_the_engines_types_for_the_same_data_are_one_value(mixed $a, mixed $b): void
    {
        $this->assertSame('', ParityValues::firstDifference(ParityValues::normalise($a), ParityValues::normalise($b)));
    }

    public static function differentValues(): array
    {
        return [
            'null and zero' => [null, 0],
            'null and empty text' => [null, ''],
            'zero and empty text' => [0, ''],
            'case' => ['Approved', 'approved'],
            'a trailing space' => ['a', 'a '],
            'a number and its text' => [5, '5'],
            '1.5 and 1.6' => [1.5, 1.6],
            'an instant an hour apart' => ['2026-01-02 09:00:00+00', '2026-01-02 10:00:00+00'],
            'a missing column' => [['a' => 1], ['a' => 1, 'b' => 2]],
            'a missing row' => [[1, 2], [1]],
        ];
    }

    #[DataProvider('differentValues')]
    public function test_real_differences_survive(mixed $a, mixed $b): void
    {
        $this->assertNotSame('', ParityValues::firstDifference(ParityValues::normalise($a), ParityValues::normalise($b)));
    }

    public function test_empty_results_carry_no_information(): void
    {
        foreach ([null, [], 0, '', false, collect()] as $v) {
            $this->assertTrue(ParityValues::isEmpty(ParityValues::normalise($v)), var_export($v, true));
        }
        foreach ([[1], 3, 'x', true, ['a' => null]] as $v) {
            $this->assertFalse(ParityValues::isEmpty(ParityValues::normalise($v)), var_export($v, true));
        }
    }

    public function test_the_same_rows_in_two_orders_sort_alike(): void
    {
        $a = ParityValues::sortUnordered(ParityValues::normalise([['id' => 2], ['id' => 1], ['id' => 3]]));
        $b = ParityValues::sortUnordered(ParityValues::normalise([['id' => 3], ['id' => 2], ['id' => 1]]));
        $this->assertSame($a, $b);
    }

    public function test_the_difference_names_the_row_and_column(): void
    {
        $a = ParityValues::normalise([['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'y']]);
        $b = ParityValues::normalise([['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'z']]);
        $this->assertStringStartsWith('result[1].name:', ParityValues::firstDifference($a, $b));
    }
}
