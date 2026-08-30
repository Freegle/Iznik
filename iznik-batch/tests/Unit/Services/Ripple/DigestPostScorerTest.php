<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\DigestPostScorer;
use Tests\TestCase;

class DigestPostScorerTest extends TestCase
{
    private array $weights = ['close' => 1.0, 'fresh' => 0.0, 'budget' => 1.0, 'anchor' => 0.0];
    private array $env = ['window_hours' => 24.0, 'budget_decay' => 25.0];

    private function scorer(): DigestPostScorer
    {
        return new DigestPostScorer();
    }

    public function test_close_term_is_one_at_origin_and_clamps_to_zero_beyond_reach(): void
    {
        $s = $this->scorer();

        $atOrigin = $s->score(0.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(1.0, $atOrigin['close'], 1e-9);

        $beyond = $s->score(2000.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $beyond['close']);
    }

    public function test_fresh_term_decays_linearly_and_clamps(): void
    {
        $s = $this->scorer();
        $half = $s->score(0.0, 1000.0, 12.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(0.5, $half['fresh'], 1e-9);
        $old = $s->score(0.0, 1000.0, 48.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $old['fresh']);
    }

    public function test_budget_term_matches_go_reference_with_age_clamp(): void
    {
        $s = $this->scorer();
        $unseen = $s->score(0.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(1.0, $unseen['budget'], 1e-9);

        $expected = exp(-16.0 / (25.0 / 12.0));
        $busy = $s->score(0.0, 1000.0, 0.25, 10, 2, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta($expected, $busy['budget'], 1e-9);
    }

    public function test_anchor_term_is_one_only_for_home_group(): void
    {
        $s = $this->scorer();
        $home = $s->score(0.0, 1000.0, 5.0, 0, 0, true, $this->weights, $this->env);
        $this->assertSame(1.0, $home['anchor']);
        $away = $s->score(0.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $away['anchor']);
    }

    public function test_total_is_weighted_sum_with_explorer_default_weights(): void
    {
        $s = $this->scorer();
        $r = $s->score(200.0, 1000.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertEqualsWithDelta(0.8, $r['close'], 1e-9);
        $this->assertEqualsWithDelta(1.8, $r['total'], 1e-9);
    }

    public function test_zero_reach_radius_does_not_divide_by_zero(): void
    {
        $s = $this->scorer();
        $r = $s->score(50.0, 0.0, 5.0, 0, 0, false, $this->weights, $this->env);
        $this->assertSame(0.0, $r['close']);
        $this->assertFalse(is_nan($r['total']));
    }

    // --- Reference close term: 1 - driveMin/max_minutes (drive time known) ---

    public function test_close_uses_drive_minutes_when_known(): void
    {
        $env = $this->env + ['max_minutes' => 30.0];
        // 15 of 30 minutes: close = 0.5, regardless of the crow inputs.
        $r = $this->scorer()->score(999999.0, 100.0, 5.0, 0, 0, false, $this->weights, $env, 15.0);
        $this->assertEqualsWithDelta(0.5, $r['close'], 1e-9);
        // Beyond the horizon clamps to zero.
        $r = $this->scorer()->score(0.0, 100000.0, 5.0, 0, 0, false, $this->weights, $env, 45.0);
        $this->assertSame(0.0, $r['close']);
    }

    public function test_unknown_drive_time_falls_back_to_the_crow_proxy(): void
    {
        // null driveMinutes means "unknown", never "score zero": the documented
        // haversine approximation still applies, byte-identical to before.
        $env = $this->env + ['max_minutes' => 30.0];
        $r = $this->scorer()->score(15000.0, 30000.0, 5.0, 0, 0, false, $this->weights, $env, null);
        $this->assertEqualsWithDelta(0.5, $r['close'], 1e-9);
    }

    public function test_missing_max_minutes_keeps_the_crow_proxy_even_with_minutes(): void
    {
        // An env without the horizon (older config) cannot apply the reference
        // term; the proxy governs and nothing divides by zero.
        $r = $this->scorer()->score(15000.0, 30000.0, 5.0, 0, 0, false, $this->weights, $this->env, 10.0);
        $this->assertEqualsWithDelta(0.5, $r['close'], 1e-9);
    }
}
