<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResolvesFreegleConfig;
use Tests\TestCase;

/**
 * The three whole-number settings that go with the earned-reach gate and rippled-in copies are
 * read so that a blank value means the default and an explicit 0 still means off.
 *
 * docker-compose passes a variable nobody set to the batch container as an empty string
 * (`${RIPPLE_AUTOAPPROVE_HOLD_SECONDS:-}`), and Laravel's env() returns that "" rather than the
 * default. Read with a bare `(int)` cast it became 0, and ExpandService treats 0 as "hold off"
 * and "cap off", so turning the earned-reach gate on would have done almost nothing: a hold of 0
 * seconds and a cap of 0 views instead of 3600 and 5. Nothing complained: it is a valid setting.
 */
class RippleNumbersBlankEnvTest extends TestCase
{
    use ResolvesFreegleConfig;

    /**
     * @return array<string, array{0: string, 1: string, 2: int}> env name, config key, default
     */
    public static function settings(): array
    {
        return [
            'hold seconds' => ['RIPPLE_AUTOAPPROVE_HOLD_SECONDS', 'autoapprove_hold_seconds', 3600],
            'clean views per community' => ['RIPPLE_CLEAN_VIEWS_PER_GROUP', 'clean_views_per_group', 5],
            'hours a rippled-in copy waits' => ['RIPPLE_RIPPLED_IN_PENDING_HOURS', 'rippled_in_pending_hours', 0],
        ];
    }

    private function resolved(string $name, string $key, ?string $value): int
    {
        return $this->freegleConfigWith($name, $value)['ripple'][$key];
    }

    #[DataProvider('settings')]
    public function test_a_blank_value_is_the_default(string $name, string $key, int $default): void
    {
        $this->assertSame($default, $this->resolved($name, $key, ''), 'compose passes an unset variable as an empty string');
        $this->assertSame($default, $this->resolved($name, $key, '   '));
    }

    #[DataProvider('settings')]
    public function test_a_variable_that_is_not_set_is_the_default(string $name, string $key, int $default): void
    {
        $this->assertSame($default, $this->resolved($name, $key, null));
    }

    #[DataProvider('settings')]
    public function test_an_explicit_value_is_used_and_an_explicit_zero_is_zero(string $name, string $key, int $default): void
    {
        $this->assertSame(0, $this->resolved($name, $key, '0'), 'zero is a real setting: hold off, cap off, immediate approve');
        $this->assertSame(0, $this->resolved($name, $key, ' 0 '));
        $this->assertSame(1800, $this->resolved($name, $key, '1800'));
        $this->assertSame(7, $this->resolved($name, $key, ' 7 '));
    }

    #[DataProvider('settings')]
    public function test_a_typo_is_the_default_not_the_leading_digits_or_zero(string $name, string $key, int $default): void
    {
        foreach (['1h', 'abc', '-1', '2.5', '1e3', 'true'] as $typo) {
            $this->assertSame($default, $this->resolved($name, $key, $typo), $typo);
        }
    }

    public function test_the_defaults_are_the_documented_ones(): void
    {
        $ripple = $this->freegleConfigWith('RIPPLE_AUTOAPPROVE_HOLD_SECONDS', '')['ripple'];

        $this->assertSame(3600, $ripple['autoapprove_hold_seconds']);
        $this->assertSame(5, $ripple['clean_views_per_group']);
        $this->assertSame(0, $ripple['rippled_in_pending_hours']);
    }
}
