<?php

namespace App\Services\Ux;

/**
 * A small seeded random number generator that belongs to whoever made it.
 *
 * The experiment statistics sample posteriors with a fixed seed so a decision is repeatable. Doing
 * that by calling mt_srand() reseeds the whole process, which would silently make every other
 * mt_rand() in the same worker (a queue worker runs for days) deterministic. This keeps the state
 * in an object instead. It is xorshift32: ample for Monte Carlo draws, and every step is a shift,
 * xor or mask, so it cannot overflow PHP's 64-bit integers.
 */
final class UxRng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
        // Mix the seed so nearby seeds (a window length, plus 7) do not start in step.
        for ($i = 0; $i < 4; $i++) {
            $this->next();
        }
    }

    /** The next 32-bit value. */
    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;

        return $this->state = $x & 0xFFFFFFFF;
    }

    /** A uniform value strictly between 0 and 1. */
    public function uniform(): float
    {
        return ($this->next() + 0.5) / 4294967296.0;
    }

    /** A standard normal value (Box-Muller). */
    public function normal(): float
    {
        $u1 = max($this->uniform(), 1e-12);
        $u2 = $this->uniform();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }
}
