<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The privacy gate on the survey coder (UX_PRIVACY_CLEARED) opens only for the value 1 or the word
 * true, read from the real environment.
 *
 * A bare (bool) cast of the variable would read "off" and "no" as true, and PHP's own filter would
 * read "yes" as true; either would send members' free text to an AI service on a word that merely
 * looks like a value. The other tests set the config directly, so they cannot catch a wrong cast:
 * this one loads config/freegle.php the way BlankEnvMeansDefaultTest does, with the variable set
 * where Laravel's env() reads it ($_ENV, $_SERVER and putenv), and puts every one back afterwards.
 */
class UxPrivacyGateEnvTest extends TestCase
{
    private const NAME = 'UX_PRIVACY_CLEARED';

    /** The gate as config/freegle.php resolves it with the variable set to $value, or removed when null. */
    private function gateWith(?string $value): mixed
    {
        $saved = [
            'env' => array_key_exists(self::NAME, $_ENV) ? [$_ENV[self::NAME]] : null,
            'server' => array_key_exists(self::NAME, $_SERVER) ? [$_SERVER[self::NAME]] : null,
            'getenv' => getenv(self::NAME),
        ];

        try {
            if ($value === null) {
                putenv(self::NAME);
                unset($_ENV[self::NAME], $_SERVER[self::NAME]);
            } else {
                putenv(self::NAME.'='.$value);
                $_ENV[self::NAME] = $value;
                $_SERVER[self::NAME] = $value;
            }

            return (require base_path('config/freegle.php'))['ux']['prompts']['privacy_cleared'];
        } finally {
            if ($saved['getenv'] === false) {
                putenv(self::NAME);
            } else {
                putenv(self::NAME.'='.$saved['getenv']);
            }
            if ($saved['env'] === null) {
                unset($_ENV[self::NAME]);
            } else {
                $_ENV[self::NAME] = $saved['env'][0];
            }
            if ($saved['server'] === null) {
                unset($_SERVER[self::NAME]);
            } else {
                $_SERVER[self::NAME] = $saved['server'][0];
            }
        }
    }

    public function test_the_privacy_gate_opens_only_for_one_or_true_in_the_environment(): void
    {
        foreach (['1', 'true', 'TRUE', 'True', ' true '] as $open) {
            $this->assertTrue($this->gateWith($open), "'$open' opens the gate");
        }
        foreach (['off', 'no', '0', 'false', 'FALSE', '', '   ', 'yes', 'on', 'cleared', '2', 'enabled'] as $closed) {
            $this->assertFalse($this->gateWith($closed), "'$closed' leaves the gate closed");
        }
        $this->assertFalse($this->gateWith(null), 'unset leaves the gate closed');
    }

    public function test_the_environment_is_left_as_it_was(): void
    {
        $before = [$_ENV[self::NAME] ?? null, $_SERVER[self::NAME] ?? null, getenv(self::NAME)];

        $this->gateWith('1');
        $this->gateWith(null);

        $this->assertSame($before, [$_ENV[self::NAME] ?? null, $_SERVER[self::NAME] ?? null, getenv(self::NAME)]);
    }
}
