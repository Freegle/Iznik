<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The repost settings, the same for every community.
 *
 * They live in one row of the `config` table: key `reposts`, value JSON
 * {"offer":3,"wanted":7,"max":3,"chaseups":5}.
 *   - offer / wanted: days an OFFER / WANTED waits before it is reposted (and again between reposts).
 *   - max: how many times a post is reposted before the poster is chased up instead.
 *   - chaseups: days between chase-up emails.
 *
 * A community's own `settings.reposts` is ignored; it is left in the database untouched.
 * The Go API (group.SystemRepostSettings) and the frontend (GROUP_REPOSTS in constants.js)
 * hold the same four fallback values; change all three together.
 */
class RepostSettings
{
    public const CONFIG_KEY = 'reposts';

    /** Used when the config row is missing or a value in it is unusable. */
    public const FALLBACK = [
        'offer' => 3,
        'wanted' => 7,
        'max' => 3,
        'chaseups' => 5,
    ];

    /**
     * Read the row. Cheap (one lookup by unique key on a tiny table), so callers read it once
     * at the start of a run and pass the array on rather than this class caching it between tests.
     *
     * @return array{offer:int,wanted:int,max:int,chaseups:int}
     */
    public static function get(): array
    {
        $raw = DB::table('config')->where('key', self::CONFIG_KEY)->value('value');

        if ($raw === null) {
            Log::warning('Repost settings: no `' . self::CONFIG_KEY . '` row in config; using the built-in values.');

            return self::FALLBACK;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            Log::warning('Repost settings: the `' . self::CONFIG_KEY . '` config row is not a JSON object; using the built-in values.');

            return self::FALLBACK;
        }

        $settings = [];

        foreach (self::FALLBACK as $key => $fallback) {
            $value = $decoded[$key] ?? null;

            // Whole, non-negative numbers only. 0 is meaningful (reposting off), so it is kept.
            if (self::isWholeNumber($value)) {
                $settings[$key] = (int) $value;
            } else {
                Log::warning("Repost settings: `{$key}` in the `" . self::CONFIG_KEY . '` config row is missing or not a whole number; using ' . $fallback . '.');
                $settings[$key] = $fallback;
            }
        }

        return $settings;
    }

    private static function isWholeNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        if (is_float($value)) {
            return $value >= 0 && floor($value) === $value;
        }

        return is_string($value) && ctype_digit($value);
    }
}
