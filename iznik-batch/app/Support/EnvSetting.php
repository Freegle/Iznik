<?php

namespace App\Support;

use Illuminate\Support\Env;

/**
 * The one rule for reading the post-moderation settings from the environment: the master switch,
 * the wait, the group lists, the automod timeout and address, and the ripple settings that go with
 * them (the earned-reach gate and its numbers). The Go API applies the same rule to the same
 * variables (iznik-server-go/utils/autoapprovetrial.go), so the batch and ModTools cannot
 * disagree about whether a community is in the trial. The cases are pinned in
 * tests/Unit/Support/EnvSettingTest.php; keep the Go table test in step with it.
 *
 * Values arrive as the strings docker-compose passes through ("" when a variable nobody set is
 * written as `${NAME:-}`), or as real booleans and integers when a test sets config directly.
 * Laravel's env() returns that "" rather than the default, and a bare (int) or (bool) cast reads
 * it as 0 or false, so every setting here treats blank as "not set": the default (off, for a
 * switch), never a value.
 *
 * env() is the same rule, without the strictness, for every other setting in config/freegle.php:
 * blank is the default.
 */
final class EnvSetting
{
    /** A positive whole number is at most this many digits; longer is a typo, not a setting. */
    private const MAX_INT_DIGITS = 9;

    /** A group id is at most this many digits, so a typo cannot overflow into a real id. */
    private const MAX_GROUP_ID_DIGITS = 18;

    /**
     * Laravel's env(), except that a variable that is unset, empty or only spaces is the default.
     *
     * docker-compose passes a variable nobody set as "" (`${NAME:-}`), and env('NAME', $default)
     * returns that "" instead of the default. The cast at the call site then makes it 0, 0.0 or
     * false, or leaves an empty string where a URL or an address was expected, and nothing fails.
     *
     * Anything else comes back exactly as env() gives it, so the casts and defaults at the call
     * sites keep their meaning: "yes" is still the string "yes", "false" is still the boolean
     * false and a closure default is still called. Laravel's own words stay too, including
     * "(empty)", its way of asking for an empty string on purpose; that is why the raw variable
     * is tested here and not env()'s answer, which has already turned "(empty)" into "".
     *
     * The post-moderation settings are stricter than this and use the readers below.
     */
    public static function env(string $name, mixed $default = null): mixed
    {
        $raw = Env::getRepository()->get($name);

        if ($raw === null || trim($raw) === '') {
            return value($default);
        }

        return Env::get($name, $default);
    }

    /**
     * A switch is on when its trimmed value is "true" or "1", in any case. Anything else is off,
     * including "yes", "on", "off" and "no": a master switch that publishes posts must not be
     * turned on by a word that merely looks like a value (PHP's own cast reads "off" as true).
     */
    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        $v = strtolower(trim($value));

        return $v === 'true' || $v === '1';
    }

    /**
     * A positive whole number, else the default. Blank, zero, negative, fractional, exponent and
     * non-numeric values all mean the default: a typo must never give a wait of zero.
     */
    public static function positiveInt(mixed $value, int $default): int
    {
        $n = self::whole($value);

        return $n !== null && $n > 0 ? $n : $default;
    }

    /**
     * A whole number from zero up, else the default: for a setting where zero is a real choice (a
     * hold of 0 seconds is "no hold", a cap of 0 views is "no cap") but a blank is not one.
     * Blank, negative, fractional, exponent and non-numeric values all mean the default, so an
     * unset variable that docker-compose passes as "" keeps the default instead of reading as an
     * explicit 0, and "1h" is the default, not 1. An explicit "0" is 0.
     */
    public static function wholeNumber(mixed $value, int $default): int
    {
        return self::whole($value) ?? $default;
    }

    /**
     * A text setting (an address, a name): the trimmed value, or the default when it is blank or
     * not a string.
     */
    public static function text(mixed $value, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $v = trim($value);

        return $v !== '' ? $v : $default;
    }

    /** The value as a whole number from zero up, or null when it is not one. */
    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $v = trim($value);
        if (!preg_match('/^\+?\d{1,' . self::MAX_INT_DIGITS . '}$/D', $v)) {
            return null;
        }

        return (int) $v;
    }

    /**
     * Group ids from a comma-separated list. A token counts only when it is plain ASCII digits
     * (after trimming); anything else is dropped, never guessed at, so "12abc" or "1e3" is not
     * read as a community. Zero is not a group.
     *
     * @return int[]
     */
    public static function groupIds(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if (!preg_match('/^\d{1,' . self::MAX_GROUP_ID_DIGITS . '}$/D', $token)) {
                continue;
            }

            $id = (int) $token;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
