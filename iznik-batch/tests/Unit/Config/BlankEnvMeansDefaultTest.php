<?php

namespace Tests\Unit\Config;

use App\Support\EnvSetting;
use Tests\TestCase;

/**
 * A blank environment variable means "not set" everywhere in config/freegle.php.
 *
 * docker-compose and the .env files pass a variable nobody set through as an empty string
 * (`${NAME:-}`). Laravel's env('NAME', $default) returns that "" rather than the default, and the
 * casts at the call sites then turn it into 0, 0.0, false or '': (int) '' is 0, and both (bool) ''
 * and filter_var('', FILTER_VALIDATE_BOOLEAN) are false. So a switch that is on by default went
 * off, a cap went to zero and a URL went missing, with no error anywhere.
 *
 * Every read in the file goes through EnvSetting::env, which treats blank as the default. This is
 * the guard for the whole file: it finds every variable the file names, loads the file once with
 * all of them removed and once with all of them set to "", and requires the two results to be
 * identical. A new bare env() read with a non-blank default fails it, whoever adds it.
 *
 * The post-moderation settings read through EnvSetting::flag, wholeNumber and the like are stricter
 * than this (a typo is the default too) and are pinned in EnvSettingTest, RippleNumbersBlankEnvTest
 * and EarnedReachSwitchTest; here they only have to agree that blank is the default.
 */
class BlankEnvMeansDefaultTest extends TestCase
{
    /** A variable no real config reads, for the tests of the helper itself. */
    private const PROBE = 'BLANK_ENV_HELPER_TEST_VARIABLE';

    /**
     * Every variable name config/freegle.php reads: env('NAME' ...) and EnvSetting::env('NAME' ...),
     * on one line or split across two, and the nested defaults inside either.
     *
     * @return string[]
     */
    private function referencedVariables(): array
    {
        $source = (string) file_get_contents(base_path('config/freegle.php'));
        preg_match_all("/\benv\(\s*'([A-Za-z0-9_]+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Run $then with the given variables set (a string) or removed (null), the way a container's
     * environment would present them, and put every one of them back afterwards.
     *
     * The variables are written to the places Laravel's env() reads ($_ENV, $_SERVER and
     * putenv) instead of through Env::getRepository(). That repository is immutable: it refuses
     * to change a variable the real environment already defines (phpunit.xml's, or the
     * container's), and a test that quietly left such a variable alone would prove nothing about
     * it.
     *
     * @param array<string, string|null> $environment
     */
    private function withEnvironment(array $environment, callable $then): mixed
    {
        $saved = [];
        foreach (array_keys($environment) as $name) {
            $saved[$name] = [
                'env' => array_key_exists($name, $_ENV) ? [$_ENV[$name]] : null,
                'server' => array_key_exists($name, $_SERVER) ? [$_SERVER[$name]] : null,
                'getenv' => getenv($name),
            ];
        }

        try {
            foreach ($environment as $name => $value) {
                if ($value === null) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }

            return $then();
        } finally {
            foreach ($saved as $name => $was) {
                if ($was['getenv'] === false) {
                    putenv($name);
                } else {
                    putenv("{$name}={$was['getenv']}");
                }

                if ($was['env'] === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $was['env'][0];
                }

                if ($was['server'] === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $was['server'][0];
                }
            }
        }
    }

    /**
     * Evaluate config/freegle.php with the given variables set or removed.
     *
     * @param array<string, string|null> $environment
     * @return array<string, mixed> the whole resolved file
     */
    private function configWith(array $environment): array
    {
        return $this->withEnvironment($environment, fn () => require base_path('config/freegle.php'));
    }

    /**
     * The dotted paths where two resolved configs differ, each with what the two sides hold, so a
     * failure names the settings that read a blank as a value instead of dumping the whole file.
     *
     * @return string[]
     */
    private function differences(array $notSet, array $blank, string $prefix = ''): array
    {
        $out = [];
        foreach ($notSet + $blank as $key => $unused) {
            $path = $prefix . $key;
            $a = $notSet[$key] ?? null;
            $b = $blank[$key] ?? null;

            if (is_array($a) && is_array($b)) {
                $out = array_merge($out, $this->differences($a, $b, $path . '.'));
            } elseif ($a !== $b || array_key_exists($key, $notSet) !== array_key_exists($key, $blank)) {
                $out[] = sprintf('%s: not set => %s, blank => %s', $path, var_export($a, true), var_export($b, true));
            }
        }

        return $out;
    }

    public function test_the_file_names_enough_variables_for_the_comparison_to_mean_something(): void
    {
        $names = $this->referencedVariables();

        // A regex that stopped matching would turn the test below into a comparison of a file with
        // itself. The file reads several hundred variables; require most of them to be found, and
        // a few from each end and from the multi-line call.
        $this->assertGreaterThan(300, count($names));
        $this->assertContains('FREEGLE_USER_SITE', $names, 'the first read in the file');
        $this->assertContains('RIPPLE_DENSITY_K', $names, 'a nested, indented read');
        $this->assertContains('DISCOURSE_AGM_DESCRIPTION', $names, 'a call with the name on the next line');
        $this->assertContains('FREEGLE_SPAM_ADDR', $names, 'a read whose default is another read');
        $this->assertContains('ELECTRICALS_VARIANT_MIN_POPULAR_COUNT', $names, 'the last read in the file');
    }

    public function test_a_blank_variable_gives_the_same_config_as_one_that_is_not_set(): void
    {
        $names = $this->referencedVariables();

        $notSet = $this->configWith(array_fill_keys($names, null));
        $blank = $this->configWith(array_fill_keys($names, ''));

        $differences = $this->differences($notSet, $blank);
        $this->assertSame(
            [],
            $differences,
            "These settings read a blank variable as a value instead of as the default:\n  "
                . implode("\n  ", $differences)
                . "\nRead the variable through EnvSetting::env instead of a bare env()."
        );
        $this->assertSame($notSet, $blank);
    }

    public function test_a_variable_of_only_spaces_is_blank_too(): void
    {
        $names = $this->referencedVariables();

        $notSet = $this->configWith(array_fill_keys($names, null));
        $spaces = $this->configWith(array_fill_keys($names, "  \t "));

        $this->assertSame([], $this->differences($notSet, $spaces));
    }

    public function test_an_explicit_value_still_wins(): void
    {
        $this->assertSame(7, $this->configWith(['RIPPLE_DENSITY_K' => '7'])['ripple']['density']['k']);
        $this->assertSame('Another Name', $this->configWith(['FREEGLE_SITE_NAME' => 'Another Name'])['branding']['name']);
        $this->assertSame(12.5, $this->configWith(['RIPPLE_MAX_MINUTES' => '12.5'])['ripple']['max_minutes']);
    }

    public function test_an_explicit_zero_and_an_explicit_false_are_not_blank(): void
    {
        // Blank is the only thing that falls back: a deliberate 0 or false still switches the
        // thing off, which is what makes it safe for the default to be on or non-zero.
        $this->assertSame(0, $this->configWith(['RIPPLE_REPLY_SATURATION_STOP' => '0'])['ripple']['reply_saturation_stop']);
        $this->assertSame(5, $this->configWith(['RIPPLE_REPLY_SATURATION_STOP' => ''])['ripple']['reply_saturation_stop']);

        $this->assertFalse($this->configWith(['RIPPLE_REUSE_REACH' => 'false'])['ripple']['reuse_reach']);
        $this->assertFalse($this->configWith(['RIPPLE_REUSE_REACH' => '0'])['ripple']['reuse_reach']);
        $this->assertTrue($this->configWith(['RIPPLE_REUSE_REACH' => ''])['ripple']['reuse_reach']);
    }

    public function test_a_read_with_no_cast_still_gets_laravels_conversions(): void
    {
        // A bare `env('X', true)` with no cast of its own relies on Laravel turning the text
        // "false" into a real false. Routing it through EnvSetting::env must not hand back the
        // string, which is truthy.
        $this->assertFalse(
            $this->configWith(['FREEGLE_MATCHED_ENABLED' => 'false'])['matched']['enabled'],
            'the text "false" is still the boolean false'
        );
        $this->assertTrue($this->configWith(['FREEGLE_MATCHED_ENABLED' => 'true'])['matched']['enabled']);
        $this->assertTrue($this->configWith(['FREEGLE_MATCHED_ENABLED' => ''])['matched']['enabled'], 'blank is the default, on');
    }

    public function test_a_default_that_is_another_variable_follows_the_chain(): void
    {
        // FREEGLE_SPAM_ADDR defaults to FREEGLE_SUPPORT_ADDR, which defaults to an address.
        // A blank outer variable falls back to the inner one, and a blank inner one to its own default.
        $inner = $this->configWith(['FREEGLE_SPAM_ADDR' => '', 'FREEGLE_SUPPORT_ADDR' => 'help@example.org']);
        $this->assertSame('help@example.org', $inner['mail']['spam_addr']);

        $neither = $this->configWith(['FREEGLE_SPAM_ADDR' => '', 'FREEGLE_SUPPORT_ADDR' => '']);
        $this->assertSame('support@ilovefreegle.org', $neither['mail']['spam_addr']);

        $outer = $this->configWith(['FREEGLE_SPAM_ADDR' => 'spam@example.org', 'FREEGLE_SUPPORT_ADDR' => 'help@example.org']);
        $this->assertSame('spam@example.org', $outer['mail']['spam_addr']);
    }

    public function test_the_helper_returns_the_default_for_unset_blank_and_spaces(): void
    {
        foreach ([null, '', ' ', "\t\n"] as $value) {
            $label = var_export($value, true);

            $this->withEnvironment([self::PROBE => $value], function () use ($label) {
                $this->assertSame(42, EnvSetting::env(self::PROBE, 42), $label);
                $this->assertSame('x', EnvSetting::env(self::PROBE, 'x'), $label);
                $this->assertNull(EnvSetting::env(self::PROBE), $label . ' with no default');
                $this->assertSame('computed', EnvSetting::env(self::PROBE, fn () => 'computed'), $label . ' with a closure default');
            });
        }
    }

    public function test_the_helper_returns_what_env_returns_for_anything_else(): void
    {
        // The same conversions Laravel's env() applies, so only blank changes.
        $cases = [
            '7' => '7',
            ' 7 ' => ' 7 ',
            'yes' => 'yes',
            'off' => 'off',
            '0' => '0',
            'true' => true,
            '(true)' => true,
            'false' => false,
            '(false)' => false,
            'null' => null,
            '(null)' => null,
            // Laravel's own way to ask for an empty string, which stays possible.
            'empty' => '',
            '(empty)' => '',
        ];

        foreach ($cases as $value => $expected) {
            $this->withEnvironment([self::PROBE => (string) $value], function () use ($value, $expected) {
                $this->assertSame($expected, EnvSetting::env(self::PROBE, 'default'), "set to '{$value}'");
                $this->assertSame(env(self::PROBE, 'default'), EnvSetting::env(self::PROBE, 'default'), "same as env() for '{$value}'");
            });
        }
    }
}
