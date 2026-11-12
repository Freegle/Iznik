<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResolvesFreegleConfig;
use Tests\TestCase;

/**
 * RIPPLE_EARNED_REACH_ENABLED is read by both the batch (the gate on rippling) and the Go API
 * (the reviewer pool in microvolunteering.go), so both must say the same thing for the same
 * value. The rule is the one every post-moderation switch uses (App\Support\EnvSetting::flag,
 * pinned in Tests\Unit\Support\EnvSettingTest): on for "true" or "1", in any case, and nothing
 * else. This used to be `(bool) env(...)`, which PHP reads "off", "no" and "yes" as ON, so a
 * value the Go side (rightly) took as off turned the batch's gate on.
 *
 * This pins how config/freegle.php applies that rule to this one variable; the rule itself is
 * pinned in EnvSettingTest.
 */
class EarnedReachSwitchTest extends TestCase
{
    use ResolvesFreegleConfig;

    /** @return array<string, array{0: string, 1: bool}> */
    public static function values(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'one' => ['1', true],
            'padded one' => [' 1 ', true],
            'padded true' => ['  true ', true],
            'yes is not on' => ['yes', false],
            'on is not on' => ['on', false],
            'off is off' => ['off', false],
            'no is off' => ['no', false],
            'disabled is off' => ['disabled', false],
            'false' => ['false', false],
            'zero' => ['0', false],
            'two' => ['2', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('values')]
    public function test_the_gate_reads_the_value_by_the_one_rule(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->freegleConfigWith('RIPPLE_EARNED_REACH_ENABLED', $value)['ripple']['earned_reach_enabled']);
    }

    public function test_the_gate_is_off_when_the_variable_is_not_set_at_all(): void
    {
        $this->assertFalse($this->freegleConfigWith('RIPPLE_EARNED_REACH_ENABLED', null)['ripple']['earned_reach_enabled']);
    }
}
