<?php

namespace Tests\Unit\Ux;

use App\Services\Ux\BetaBinomial;
use PHPUnit\Framework\TestCase;

class BetaBinomialTest extends TestCase
{
    public function test_identical_arms_are_a_coin_toss(): void
    {
        $this->assertEqualsWithDelta(0.5, BetaBinomial::probabilityBGreaterThanA(50, 50, 50, 50), 1e-9);
        $this->assertEqualsWithDelta(0.5, BetaBinomial::probabilityBGreaterThanA(0, 0, 0, 0), 1e-9);
    }

    public function test_known_values_from_the_closed_form(): void
    {
        // Checked against an independent evaluation of the same closed form: A 10 of 90, B 15 of 85.
        $this->assertEqualsWithDelta(0.8885, BetaBinomial::probabilityBGreaterThanA(10, 80, 15, 70), 0.001);
        // The complement: swapping the arms gives one minus the result.
        $p = BetaBinomial::probabilityBGreaterThanA(200, 220, 230, 190);
        $this->assertEqualsWithDelta(1 - $p, BetaBinomial::probabilityBGreaterThanA(230, 190, 200, 220), 1e-9);
    }

    public function test_a_clear_winner_is_near_certain_and_a_clear_loser_near_zero(): void
    {
        $this->assertGreaterThan(0.999, BetaBinomial::probabilityBGreaterThanA(100, 300, 200, 200));
        $this->assertLessThan(0.001, BetaBinomial::probabilityBGreaterThanA(200, 200, 100, 300));
    }

    public function test_small_samples_stay_uncertain(): void
    {
        // 6 of 10 against 4 of 10 is nowhere near a decision.
        $p = BetaBinomial::probabilityBGreaterThanA(4, 6, 6, 4);
        $this->assertGreaterThan(0.6, $p);
        $this->assertLessThan(0.9, $p);
    }

    public function test_result_is_always_a_probability(): void
    {
        foreach ([[0, 0, 0, 1], [1, 0, 0, 0], [1000, 1, 1, 1000], [3, 0, 0, 3]] as [$sa, $fa, $sb, $fb]) {
            $p = BetaBinomial::probabilityBGreaterThanA($sa, $fa, $sb, $fb);
            $this->assertGreaterThanOrEqual(0.0, $p);
            $this->assertLessThanOrEqual(1.0, $p);
        }
    }

    public function test_credible_interval_is_deterministic_and_brackets_the_true_difference(): void
    {
        [$lo, $hi] = BetaBinomial::credibleIntervalDiff(200, 200, 240, 160);
        [$lo2, $hi2] = BetaBinomial::credibleIntervalDiff(200, 200, 240, 160);
        $this->assertSame($lo, $lo2);
        $this->assertSame($hi, $hi2);
        // The true difference is 0.10; a 90% interval on 400 per arm is roughly 0.10 +/- 0.06.
        $this->assertLessThan(0.10, $lo);
        $this->assertGreaterThan(0.10, $hi);
        $this->assertGreaterThan(0.0, $lo);
        $this->assertLessThan(0.2, $hi);
    }

    public function test_ln_gamma_matches_factorials(): void
    {
        $this->assertEqualsWithDelta(log(120), BetaBinomial::lnGamma(6), 1e-9);
        $this->assertEqualsWithDelta(log(3628800), BetaBinomial::lnGamma(11), 1e-9);
        $this->assertEqualsWithDelta(0.5 * log(M_PI), BetaBinomial::lnGamma(0.5), 1e-9);
    }

    public function test_posterior_mean_shrinks_towards_a_half(): void
    {
        $this->assertSame(0.5, BetaBinomial::mean(0, 0));
        $this->assertEqualsWithDelta(0.6, BetaBinomial::mean(599, 399), 1e-9);
    }

    public function test_stratified_difference_is_deterministic_and_does_not_touch_the_process_generator(): void
    {
        $strata = [['sa' => 40, 'fa' => 160, 'sb' => 20, 'fb' => 80], ['sa' => 60, 'fa' => 40, 'sb' => 120, 'fb' => 80]];
        mt_srand(99);
        $expected = [mt_rand(), mt_rand()];
        mt_srand(99);
        $one = BetaBinomial::stratifiedDiff($strata);
        $two = BetaBinomial::stratifiedDiff($strata);
        BetaBinomial::probabilityDiffExceeds(10, 90, 20, 80, 0.01);
        BetaBinomial::credibleIntervalDiff(10, 90, 20, 80);

        $this->assertSame($one, $two);
        $this->assertSame($expected, [mt_rand(), mt_rand()]);
    }

    public function test_stratified_difference_ignores_a_shift_in_the_mix_and_keeps_a_real_effect(): void
    {
        // Same rates in both arms (20% in one stratum, 60% in the other), very different mixes.
        $null = BetaBinomial::stratifiedDiff([
            ['sa' => 40, 'fa' => 160, 'sb' => 20, 'fb' => 80],
            ['sa' => 60, 'fa' => 40, 'sb' => 120, 'fb' => 80],
        ]);
        $this->assertGreaterThan(0.3, $null['p_better']);
        $this->assertLessThan(0.7, $null['p_better']);
        $this->assertLessThan(0.0, $null['lo']);
        $this->assertGreaterThan(0.0, $null['hi']);

        // Ten points better in both strata.
        $real = BetaBinomial::stratifiedDiff([
            ['sa' => 60, 'fa' => 240, 'sb' => 90, 'fb' => 210],
            ['sa' => 180, 'fa' => 120, 'sb' => 210, 'fb' => 90],
        ]);
        $this->assertGreaterThan(0.99, $real['p_better']);
        $this->assertLessThan(0.1, $real['expected_loss']);
        $this->assertLessThan(0.005, $real['expected_loss']);
    }

    public function test_probability_the_difference_exceeds_a_margin(): void
    {
        // 5% against 6%, 8,000 each: worse, but almost surely not by two points.
        $this->assertLessThan(0.05, BetaBinomial::probabilityDiffExceeds(400, 7600, 480, 7520, 0.02));
        // 5% against 10%: worse by far more than two points.
        $this->assertGreaterThan(0.99, BetaBinomial::probabilityDiffExceeds(400, 7600, 800, 7200, 0.02));
        // With no margin it is plain "is it worse".
        $this->assertGreaterThan(0.99, BetaBinomial::probabilityDiffExceeds(400, 7600, 480, 7520, 0.0));
    }

    public function test_normal_cdf(): void
    {
        $this->assertEqualsWithDelta(0.5, BetaBinomial::normalCdf(0.0), 1e-7);
        $this->assertEqualsWithDelta(0.8413447, BetaBinomial::normalCdf(1.0), 1e-6);
        $this->assertEqualsWithDelta(0.0227501, BetaBinomial::normalCdf(-2.0), 1e-6);
        $this->assertEqualsWithDelta(0.975, BetaBinomial::normalCdf(1.959964), 1e-6);
    }

    public function test_adopt_power_rises_with_volume_and_effect_and_is_small_for_nothing(): void
    {
        // Twenty starts a side cannot show five points; ten thousand can, very reliably.
        $this->assertLessThan(0.2, BetaBinomial::adoptPower(0.5, 20, 0.05, 0.8, 0.005));
        $this->assertGreaterThan(0.99, BetaBinomial::adoptPower(0.5, 10000, 0.05, 0.8, 0.005));
        $this->assertGreaterThan(BetaBinomial::adoptPower(0.5, 400, 0.05, 0.8, 0.005), BetaBinomial::adoptPower(0.5, 1600, 0.05, 0.8, 0.005));
        $this->assertGreaterThan(BetaBinomial::adoptPower(0.5, 400, 0.05, 0.8, 0.005), BetaBinomial::adoptPower(0.5, 400, 0.08, 0.8, 0.005));
        // No effect at all: only the false-adoption rate of the P(better) bar, about one in five.
        $this->assertEqualsWithDelta(0.2, BetaBinomial::adoptPower(0.5, 400, 0.0, 0.8, 0.005), 0.05);
        $this->assertSame(0.0, BetaBinomial::adoptPower(0.5, 0, 0.05, 0.8, 0.005));
    }

    public function test_the_loss_bar_can_be_the_binding_one(): void
    {
        // At 150 a side the 0.5 point loss bar needs a bigger measured gain than P(better) 0.8 does,
        // so a looser loss bar adopts more often.
        $strict = BetaBinomial::adoptPower(0.5, 150, 0.05, 0.8, 0.005);
        $loose = BetaBinomial::adoptPower(0.5, 150, 0.05, 0.8, 0.02);
        $this->assertGreaterThan($strict, $loose);
    }}
