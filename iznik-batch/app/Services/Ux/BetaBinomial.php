<?php

namespace App\Services\Ux;

/**
 * The arithmetic behind an experiment decision: two arms, each a count of successes and failures,
 * compared as Beta(1 + s, 1 + f) posteriors on their rates.
 *
 * probabilityBGreaterThanA is exact (the closed form in Miller 2015, "Formulas for Bayesian A/B
 * Testing", a sum over the smaller arm's successes of ratios of Beta functions), so a test can pin
 * it to the digit. Everything with a margin, a stratification or an expected loss has no closed
 * form and is sampled with a fixed seed, so it is deterministic too.
 *
 * Why Bayesian and not a p-value: a 24-hour experiment is read every hour, and the question the
 * loop asks is "how sure are we the change is better, and how much would we lose if we kept it
 * and it were not", which a posterior answers directly and a p-value does not.
 */
final class BetaBinomial
{
    private const SEED = 20261014;

    /**
     * P(rate_B > rate_A) given successes and failures in each arm, with uniform priors.
     */
    public static function probabilityBGreaterThanA(int $sa, int $fa, int $sb, int $fb): float
    {
        $aA = $sa + 1;
        $bA = $fa + 1;
        $aB = $sb + 1;
        $bB = $fb + 1;

        // Sum over the arm with fewer successes; the two are related by P(B>A) = 1 - P(A>B).
        if ($aB > $aA) {
            return 1.0 - self::probabilityBGreaterThanA($sb, $fb, $sa, $fa);
        }

        $total = 0.0;
        $lnBetaA = self::lnBeta($aA, $bA);
        for ($i = 0; $i < $aB; $i++) {
            $total += exp(
                self::lnBeta($aA + $i, $bA + $bB)
                - log($bB + $i)
                - self::lnBeta(1 + $i, $bB)
                - $lnBetaA
            );
        }

        return max(0.0, min(1.0, $total));
    }

    /**
     * P(rate_B - rate_A > margin): how likely B is better than A by more than a margin (a
     * non-inferiority question when the margin is negative). Sampled with a fixed seed.
     */
    public static function probabilityDiffExceeds(int $sa, int $fa, int $sb, int $fb, float $margin, int $samples = 8000): float
    {
        $rng = new UxRng(self::SEED);
        $hits = 0;
        for ($i = 0; $i < $samples; $i++) {
            if (self::sampleBeta($sb + 1, $fb + 1, $rng) - self::sampleBeta($sa + 1, $fa + 1, $rng) > $margin) {
                $hits++;
            }
        }

        return $hits / $samples;
    }

    /**
     * The central credible interval of (rate_B - rate_A) at the given mass, in rate units
     * (0.03 is three points), by sampling the two posteriors with a fixed seed.
     *
     * @return array{0: float, 1: float}
     */
    public static function credibleIntervalDiff(int $sa, int $fa, int $sb, int $fb, float $mass = 0.9, int $samples = 20000): array
    {
        $rng = new UxRng(self::SEED);
        $diffs = [];
        for ($i = 0; $i < $samples; $i++) {
            $diffs[] = self::sampleBeta($sb + 1, $fb + 1, $rng) - self::sampleBeta($sa + 1, $fa + 1, $rng);
        }

        return self::interval($diffs, $mass);
    }

    /**
     * The comparison of B against A across strata (for instance hour block times logged in), so a
     * moving traffic split cannot pass a change in the traffic mix off as an effect of the change.
     *
     * $strata is [['sa' => ..., 'fa' => ..., 'sb' => ..., 'fb' => ...], ...]. Each stratum's
     * difference is weighted by its share of all starts; strata with no starts in either arm are
     * left out and the weights renormalised. Returns the probability B is better, the central 90%
     * interval of the weighted difference, and the expected loss of choosing B (the mean of how
     * much worse than A it is, when it is worse), all in rate units.
     *
     * @return array{p_better: float, lo: float, hi: float, expected_loss: float}
     */
    public static function stratifiedDiff(array $strata, int $samples = 6000): array
    {
        $used = [];
        $total = 0;
        foreach ($strata as $s) {
            $na = ($s['sa'] ?? 0) + ($s['fa'] ?? 0);
            $nb = ($s['sb'] ?? 0) + ($s['fb'] ?? 0);
            if ($na > 0 && $nb > 0) {
                $used[] = $s + ['n' => $na + $nb];
                $total += $na + $nb;
            }
        }
        if (! $used) {
            return ['p_better' => 0.5, 'lo' => 0.0, 'hi' => 0.0, 'expected_loss' => 0.0];
        }

        $rng = new UxRng(self::SEED);
        $diffs = [];
        $hits = 0;
        $loss = 0.0;
        for ($i = 0; $i < $samples; $i++) {
            $d = 0.0;
            foreach ($used as $s) {
                $d += ($s['n'] / $total) * (self::sampleBeta($s['sb'] + 1, $s['fb'] + 1, $rng) - self::sampleBeta($s['sa'] + 1, $s['fa'] + 1, $rng));
            }
            $diffs[] = $d;
            if ($d > 0) {
                $hits++;
            } else {
                $loss -= $d;
            }
        }
        [$lo, $hi] = self::interval($diffs, 0.9);

        return ['p_better' => $hits / $samples, 'lo' => $lo, 'hi' => $hi, 'expected_loss' => $loss / $samples];
    }

    /**
     * The smallest change in a rate, in rate units, that a day of the experiment could show at
     * 80% power and 95% confidence, from the base rate and the starts expected per arm. A plain
     * two-proportion approximation; it says what is detectable, not what will happen.
     */
    public static function minimumDetectableDiff(float $rate, int $startsPerArm): ?float
    {
        if ($startsPerArm <= 0) {
            return null;
        }

        return 2.8 * sqrt(2 * $rate * (1 - $rate) / $startsPerArm);
    }

    /** The posterior mean of a rate. */
    public static function mean(int $s, int $f): float
    {
        return ($s + 1) / ($s + $f + 2);
    }

    public static function lnBeta(float $a, float $b): float
    {
        return self::lnGamma($a) + self::lnGamma($b) - self::lnGamma($a + $b);
    }

    /** Lanczos approximation, accurate to about 1e-13 for positive arguments. */
    public static function lnGamma(float $x): float
    {
        static $g = 7;
        static $c = [
            0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313,
            -176.61502916214059, 12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];

        if ($x < 0.5) {
            return log(M_PI / sin(M_PI * $x)) - self::lnGamma(1 - $x);
        }

        $x -= 1;
        $a = $c[0];
        $t = $x + $g + 0.5;
        for ($i = 1; $i < $g + 2; $i++) {
            $a += $c[$i] / ($x + $i);
        }

        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }

    /** A Beta(a, b) sample as the normalised ratio of two Gamma samples, from the caller's own generator. */
    public static function sampleBeta(float $a, float $b, UxRng $rng): float
    {
        $x = self::sampleGamma($a, $rng);
        $y = self::sampleGamma($b, $rng);

        return $x / ($x + $y);
    }

    /** @return array{0: float, 1: float} */
    private static function interval(array $diffs, float $mass): array
    {
        sort($diffs);
        $n = count($diffs);
        $lo = (int) floor((1 - $mass) / 2 * $n);
        $hi = (int) ceil((1 + $mass) / 2 * $n) - 1;

        return [$diffs[max(0, $lo)], $diffs[min($n - 1, $hi)]];
    }

    /** Marsaglia and Tsang (2000), for shape >= 1, which 1 + a count always is. */
    private static function sampleGamma(float $shape, UxRng $rng): float
    {
        $d = $shape - 1 / 3;
        $c = 1 / sqrt(9 * $d);
        while (true) {
            do {
                $x = $rng->normal();
                $v = 1 + $c * $x;
            } while ($v <= 0);
            $v = $v * $v * $v;
            $u = $rng->uniform();
            if ($u < 1 - 0.0331 * $x * $x * $x * $x) {
                return $d * $v;
            }
            if (log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    /** The standard normal cumulative distribution (Abramowitz and Stegun 26.2.17, error under 1e-7). */
    public static function normalCdf(float $x): float
    {
        if ($x < 0) {
            return 1.0 - self::normalCdf(-$x);
        }
        $t = 1.0 / (1.0 + 0.2316419 * $x);
        $poly = $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));

        return 1.0 - exp(-$x * $x / 2) / sqrt(2 * M_PI) * $poly;
    }

    /** The standard normal density. */
    public static function normalPdf(float $x): float
    {
        return exp(-$x * $x / 2) / sqrt(2 * M_PI);
    }

    /**
     * The chance a day of an experiment adopts the change when its true effect is $effect (rate
     * units, 0.05 is five points), under the adopt rule: P(better) at least $pAdopt AND the expected
     * loss of choosing the change at most $maxLoss.
     *
     * Normal approximation to the two-arm difference with $perArm starts on each arm. The measured
     * difference m has standard error se; the posterior is then about N(m, se); P(better) >=
     * pAdopt needs m >= z(pAdopt) * se, and the expected loss se * (phi(z) - z * (1 - Phi(z)))
     * (z = m / se) falls as m rises, so the loss bar is a second threshold on m found by bisection.
     * The power is the chance the measured difference clears the higher of the two. It answers
     * "if the change really is this good, how often would we say so", so a test that could never
     * adopt anything is refused before it starts.
     */
    public static function adoptPower(float $rate, int $perArm, float $effect, float $pAdopt, float $maxLoss): float
    {
        if ($perArm <= 0) {
            return 0.0;
        }
        $rate = max(0.001, min(0.999, $rate));
        $se = sqrt(2 * $rate * (1 - $rate) / $perArm);

        // z for P(better) = pAdopt, by bisection on the normal CDF.
        $lo = -8.0;
        $hi = 8.0;
        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::normalCdf($mid) < $pAdopt) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $zP = $hi;

        // z at which the expected loss (in units of se) equals the bar.
        $target = $maxLoss / $se;
        $lo = -8.0;
        $hi = 12.0;
        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            $loss = self::normalPdf($mid) - $mid * (1 - self::normalCdf($mid));
            if ($loss > $target) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $zLoss = $hi;

        $threshold = max($zP, $zLoss) * $se;

        return max(0.0, min(1.0, 1.0 - self::normalCdf(($threshold - $effect) / $se)));
    }
}
