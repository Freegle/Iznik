<?php

namespace Tests\Unit\Ux;

use App\Services\Ux\UxRng;
use PHPUnit\Framework\TestCase;

class UxRngTest extends TestCase
{
    public function test_the_same_seed_gives_the_same_sequence_and_another_seed_another(): void
    {
        $a = new UxRng(7);
        $b = new UxRng(7);
        $c = new UxRng(8);
        $seqA = [$a->next(), $a->next(), $a->next()];
        $this->assertSame($seqA, [$b->next(), $b->next(), $b->next()]);
        $this->assertNotSame($seqA, [$c->next(), $c->next(), $c->next()]);
    }

    public function test_a_zero_seed_does_not_stick_at_zero(): void
    {
        $r = new UxRng(0);
        $this->assertNotSame(0, $r->next());
    }

    public function test_uniform_values_lie_strictly_between_zero_and_one_and_are_spread(): void
    {
        $r = new UxRng(123);
        $sum = 0.0;
        for ($i = 0; $i < 20000; $i++) {
            $u = $r->uniform();
            $this->assertGreaterThan(0.0, $u);
            $this->assertLessThan(1.0, $u);
            $sum += $u;
        }
        $this->assertEqualsWithDelta(0.5, $sum / 20000, 0.01);
    }

    public function test_normal_values_have_mean_zero_and_unit_spread(): void
    {
        $r = new UxRng(321);
        $n = 20000;
        $sum = 0.0;
        $sq = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = $r->normal();
            $sum += $x;
            $sq += $x * $x;
        }
        $this->assertEqualsWithDelta(0.0, $sum / $n, 0.03);
        $this->assertEqualsWithDelta(1.0, $sq / $n, 0.05);
    }

    public function test_using_it_leaves_the_process_wide_generator_alone(): void
    {
        mt_srand(555);
        $expected = mt_rand();
        mt_srand(555);
        $r = new UxRng(1);
        $r->next();
        $this->assertSame($expected, mt_rand());
    }
}