<?php

namespace Tests\Unit\Support;

use App\Support\EnvSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule for reading the post-moderation settings from the environment. The Go API
 * (iznik-server-go/utils/autoapprovetrial.go) applies the same rule to the same variables;
 * a case added here belongs in the Go table test too. Before this, PHP cast the master switch
 * with (bool) (so "off" and "no" switched it ON) and the delay with (int) (so "abc" meant no
 * wait at all), while Go accepted exactly "true" or "1" and fell back to 20.
 *
 * The same flag() rule reads RIPPLE_EARNED_REACH_ENABLED (config/freegle.php), which Go reads with
 * utils.EnvSwitch; Tests\Unit\Config\EarnedReachSwitchTest pins that config read case for case.
 * The whole-number settings that go with it (RIPPLE_AUTOAPPROVE_HOLD_SECONDS,
 * RIPPLE_CLEAN_VIEWS_PER_GROUP, RIPPLE_RIPPLED_IN_PENDING_HOURS) use wholeNumber(), pinned in
 * Tests\Unit\Config\RippleNumbersBlankEnvTest.
 */
class EnvSettingTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function flags(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'True' => ['True', true],
            'padded true' => ['  true ', true],
            'one' => ['1', true],
            'padded one' => [' 1 ', true],
            'yes is not on' => ['yes', false],
            'on is not on' => ['on', false],
            'off is off' => ['off', false],
            'no is off' => ['no', false],
            'disabled is off' => ['disabled', false],
            'false' => ['false', false],
            'zero' => ['0', false],
            'two' => ['2', false],
            'empty' => ['', false],
            'blank' => ['   ', false],
            'typo' => ['truee', false],
            'null' => [null, false],
            'real true' => [true, true],
            'real false' => [false, false],
            'int one' => [1, true],
            'int zero' => [0, false],
        ];
    }

    #[DataProvider('flags')]
    public function test_flag(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, EnvSetting::flag($value));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function positiveInts(): array
    {
        // The default is 30 here so that a fall back to it cannot be mistaken for a value.
        return [
            'plain' => ['20', 20],
            'padded' => [' 15 ', 15],
            'plus sign' => ['+7', 7],
            'leading zeros' => ['007', 7],
            'zero' => ['0', 30],
            'negative' => ['-5', 30],
            'letters' => ['abc', 30],
            'units suffix' => ['20min', 30],
            'decimal' => ['12.5', 30],
            'exponent' => ['1e3', 30],
            'empty' => ['', 30],
            'null' => [null, 30],
            'non ascii digits' => ["\u{0662}\u{0660}", 30],
            'nine digits' => ['123456789', 123456789],
            'ten digits' => ['1234567890', 30],
            'int' => [20, 20],
            'int zero' => [0, 30],
            'int negative' => [-1, 30],
        ];
    }

    #[DataProvider('positiveInts')]
    public function test_positive_int(mixed $value, int $expected): void
    {
        $this->assertSame($expected, EnvSetting::positiveInt($value, 30));
    }

    /** @return array<string, array{0: mixed, 1: int[]}> */
    public static function groupLists(): array
    {
        return [
            'empty' => ['', []],
            'blank' => ['  ', []],
            'null' => [null, []],
            'one' => ['12', [12]],
            'spaces and trailing commas' => [' 12, 13 ,,', [12, 13]],
            'zero is no group' => ['0', []],
            'letters' => ['abc', []],
            'digits then letters' => ['12abc,13', [13]],
            'exponent' => ['1e3', []],
            'decimal' => ['12.9', []],
            'plus sign' => ['+12', []],
            'minus sign' => ['-3', []],
            'leading zeros' => ['012', [12]],
            'duplicates' => ['12,12', [12]],
            'non ascii digits' => ["\u{0663}", []],
            'too many digits' => ['1234567890123456789', []],
        ];
    }

    #[DataProvider('groupLists')]
    public function test_group_ids(mixed $value, array $expected): void
    {
        $this->assertSame($expected, EnvSetting::groupIds($value));
    }

    /**
     * For a setting where zero is a real choice (a hold of 0 seconds is "no hold") but a blank is
     * not one. docker-compose passes an unset variable as "", and a bare (int) cast reads that as
     * 0, which turned a hold of 3600 seconds and a cap of 5 views into "off".
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function wholeNumbers(): array
    {
        // The default is 30 here so that a fall back to it cannot be mistaken for a value.
        return [
            'plain' => ['20', 20],
            'padded' => [' 15 ', 15],
            'plus sign' => ['+7', 7],
            'leading zeros' => ['007', 7],
            'zero is a real setting' => ['0', 0],
            'padded zero' => [' 0 ', 0],
            'plus zero' => ['+0', 0],
            'negative' => ['-5', 30],
            'letters' => ['abc', 30],
            'units suffix' => ['20min', 30],
            'decimal' => ['12.5', 30],
            'exponent' => ['1e3', 30],
            'empty' => ['', 30],
            'blank' => ['   ', 30],
            'null' => [null, 30],
            'non ascii digits' => ["\u{0662}\u{0660}", 30],
            'nine digits' => ['123456789', 123456789],
            'ten digits' => ['1234567890', 30],
            'int' => [20, 20],
            'int zero' => [0, 0],
            'int negative' => [-1, 30],
            'real true' => [true, 30],
            'real false' => [false, 30],
        ];
    }

    #[DataProvider('wholeNumbers')]
    public function test_whole_number(mixed $value, int $expected): void
    {
        $this->assertSame($expected, EnvSetting::wholeNumber($value, 30));
    }

    public function test_whole_number_gives_the_default_when_the_default_is_zero_too(): void
    {
        // A default of zero (no hold, no cap) still reads an explicit figure.
        $this->assertSame(0, EnvSetting::wholeNumber('', 0));
        $this->assertSame(2, EnvSetting::wholeNumber('2', 0));
        $this->assertSame(0, EnvSetting::wholeNumber('2h', 0), 'a typo is the default, not the leading digits');
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function texts(): array
    {
        return [
            'a value' => ['http://automod:8090', 'http://automod:8090'],
            'padded' => ['  http://automod:8090 ', 'http://automod:8090'],
            'empty' => ['', 'http://default'],
            'blank' => ['   ', 'http://default'],
            'null' => [null, 'http://default'],
            'a number' => [5, 'http://default'],
            'real false' => [false, 'http://default'],
        ];
    }

    /** A name or address where blank means "not set": docker-compose passes an unset variable as "". */
    #[DataProvider('texts')]
    public function test_text(mixed $value, string $expected): void
    {
        $this->assertSame($expected, EnvSetting::text($value, 'http://default'));
    }
}
