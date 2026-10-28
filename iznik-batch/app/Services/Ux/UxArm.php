<?php

namespace App\Services\Ux;

use App\Support\ExperimentBucket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Server code's view of an experiment: which arm a member is in, and a record that the arm was used.
 * docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the server".
 *
 *   $arm = UxArm::for($userId, 'my-experiment');                       // 'control', 'items', ... or ''
 *   $subject = UxArm::pick($arm, ['control' => $a, 'items' => $b]);     // look the arm up BEFORE the content is built
 *   UxArm::expose($userId, 'my-experiment', $arm, 'email', $trackingId);  // after the row the decision is about exists
 *
 * The arm is a pure function of the member's id and the weights the engine publishes in the
 * `ux_experiments` config row (ExperimentBucket::armIn, the function the browser and the Go API also
 * use), so a retry that builds the same mail again gets the same arm. Never use rand().
 *
 * for() returns '' unless all of these hold, and '' is control:
 *  - the config row `ux_server_arms` is 1. Anything else, or no row, is off. Setting it to 0 puts
 *    every sender back to control within a minute and stops the exposure rows;
 *  - the name is in config/ux_switches.php, the list of experiments this code contains;
 *  - the entry for the name is well formed by the browser's own checks (useExperiment.js);
 *  - the entry is adopted (then `until` is ignored), or its `until` is ahead and no more than 15 days
 *    ahead. The browser's 25 hour rule stays the browser's.
 * A read error also returns '' and is logged.
 *
 * Both rows are read in one query and kept for a minute, because a queue worker lives for hours.
 * The copy is stamped with Carbon::now(), so Carbon::setTestNow works; flush() forgets it.
 */
final class UxArm
{
    /** The config row that switches every server arm on (1) or off (anything else). */
    public const SWITCH_KEY = 'ux_server_arms';

    /** How long a copy of the two config rows is believed. */
    private const COPY_SECONDS = 60;

    /** A running entry whose `until` is further ahead than this is not believed. */
    private const MAX_AHEAD_SECONDS = 15 * 86400;

    /** A column is varchar(16); a longer value is skipped rather than cut. */
    private const TYPE_MAX = 16;

    private const NAME = '/^[A-Za-z0-9_-]{1,64}\z/';

    private const ARM = '/^[A-Za-z0-9_-]{1,32}\z/';

    private const SALT = '/^[A-Za-z0-9_-]{1,16}\z/';

    /** Names that would be keys on a plain object in surprising ways, as in useExperiment.js. */
    private const BLOCKED = ['__proto__', 'constructor', 'prototype'];

    private const MAX_ARMS = 8;

    /** @var array{at: int, on: bool, entries: array<string, array{arms: array<string, array{0: int, 1: int}>, until: ?float, adopted: bool, salt: string}>}|null */
    private static ?array $copy = null;

    /**
     * The member's arm for an experiment, or '' (control).
     */
    public static function for(int $userId, string $name): string
    {
        if ($userId <= 0) {
            return '';
        }

        $entry = self::servingEntry($name);
        if ($entry === null) {
            return '';
        }

        return ExperimentBucket::armIn($userId, $name, $entry['arms'], $entry['salt']) ?? '';
    }

    /**
     * Whether any member can get an arm other than '' for the experiment now. A sender whose only
     * work is for the experiment asks this first, so it does nothing while the experiment is not live.
     */
    public static function serving(string $name): bool
    {
        return self::servingEntry($name) !== null;
    }

    /**
     * The experiment's entry when it is serving arms: listed in this code, the kill switch on, and an
     * entry that is adopted or whose until is ahead by no more than MAX_AHEAD_SECONDS.
     *
     * @return array{arms: array<string, array{0: int, 1: int}>, until: ?float, adopted: bool, salt: string}|null
     */
    private static function servingEntry(string $name): ?array
    {
        if (! self::listed($name)) {
            return null;
        }

        $state = self::state();
        if (! $state['on']) {
            return null;
        }

        $entry = $state['entries'][$name] ?? null;
        if ($entry === null) {
            return null;
        }

        if (! $entry['adopted']) {
            $now = Carbon::now()->getTimestamp();
            $until = $entry['until'];
            if ($until === null || $until <= $now || $until > $now + self::MAX_AHEAD_SECONDS) {
                return null;
            }
        }

        return $entry;
    }

    /**
     * The value for an arm. '' (control) and an arm the caller has no value for both give the
     * `control` value, so a wrong or missing arm can only ever show the site as it was.
     *
     * @template T
     *
     * @param  array<string, T>  $byArm  must hold a 'control' entry
     * @return T
     */
    public static function pick(?string $arm, array $byArm): mixed
    {
        if (! array_key_exists('control', $byArm)) {
            throw new \InvalidArgumentException('UxArm::pick needs a control value');
        }

        return ($arm !== null && $arm !== '' && array_key_exists($arm, $byArm)) ? $byArm[$arm] : $byArm['control'];
    }

    /**
     * Record that the member's arm was used to decide what they got: one row per member and subject,
     * written for every arm, control included, at the place of the decision, never where content is
     * drawn. Does nothing for the arm ''. A second call for the same subject is ignored. It writes on
     * the primary, never throws, and logs what it cannot do: a failed row must not stop a send, a
     * reply or a promise. It returns true only when this call wrote the row.
     *
     * $detail holds ids and numbers only. No text, address or copy is ever stored.
     *
     * @param  array<string, int|float|bool|null>|null  $detail
     */
    public static function expose(int $userId, string $name, string $arm, string $type, int $subjectId, ?string $stratum = null, ?array $detail = null): bool
    {
        if ($arm === '') {
            return false;
        }

        try {
            if ($userId <= 0 || $subjectId < 0) {
                return false;
            }
            if (mb_strlen($name) > 64 || mb_strlen($arm) > self::TYPE_MAX || mb_strlen($type) > self::TYPE_MAX
                || ($stratum !== null && mb_strlen($stratum) > self::TYPE_MAX)) {
                Log::warning("UxArm: exposure for {$name} skipped, a value is longer than its column");

                return false;
            }
            if ($detail !== null) {
                foreach ($detail as $key => $value) {
                    if (! is_string($key) || ! preg_match('/^[a-z_]{1,32}\z/', $key) || ! (is_int($value) || is_float($value) || is_bool($value) || $value === null)) {
                        Log::warning("UxArm: exposure for {$name} skipped, detail holds more than ids");

                        return false;
                    }
                }
            }

            $written = DB::table('ux_experiment_exposures')->insertOrIgnore([
                'experiment' => $name,
                'arm' => $arm,
                'userid' => $userId,
                'subject_type' => $type,
                'subject_id' => $subjectId,
                'stratum' => $stratum,
                'exposed_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                'detail' => $detail === null || $detail === [] ? null : json_encode($detail),
            ]);

            return $written > 0;
        } catch (\Throwable $e) {
            Log::warning("UxArm: could not record the exposure for {$name}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Take back the row expose wrote for a member and subject, for a send that reached no one. Never throws.
     */
    public static function retract(int $userId, string $name, string $type, int $subjectId): void
    {
        try {
            DB::table('ux_experiment_exposures')
                ->where('experiment', $name)
                ->where('userid', $userId)
                ->where('subject_type', $type)
                ->where('subject_id', $subjectId)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning("UxArm: could not take back the exposure for {$name}: ".$e->getMessage());
        }
    }

    /**
     * Forget the copy of the config rows, so the next call reads them again (tests).
     */
    public static function flush(): void
    {
        self::$copy = null;
    }

    /**
     * Whether this code contains a switch for the experiment: it has an entry in config/ux_switches.php.
     */
    private static function listed(string $name): bool
    {
        foreach ((array) config('ux_switches', []) as $switch) {
            if (is_array($switch) && ($switch['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The two config rows, at most a minute old. A read error is remembered for the same minute as
     * "off", so a database that is down is not asked again by every call.
     *
     * @return array{at: int, on: bool, entries: array<string, array{arms: array<string, array{0: int, 1: int}>, until: ?float, adopted: bool, salt: string}>}
     */
    private static function state(): array
    {
        $now = Carbon::now()->getTimestamp();
        $copy = self::$copy;
        if ($copy !== null && $now >= $copy['at'] && $now - $copy['at'] < self::COPY_SECONDS) {
            return $copy;
        }

        try {
            $rows = DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, self::SWITCH_KEY])->pluck('value', 'key');
            $on = trim((string) ($rows[self::SWITCH_KEY] ?? '')) === '1';
            $copy = ['at' => $now, 'on' => $on, 'entries' => $on ? self::entries((string) ($rows[UxExperimentService::CONFIG_KEY] ?? '')) : []];
        } catch (\Throwable $e) {
            Log::warning('UxArm: could not read the experiment config, so every arm is control for a minute: '.$e->getMessage());
            $copy = ['at' => $now, 'on' => false, 'entries' => []];
        }
        self::$copy = $copy;

        return $copy;
    }

    /**
     * The entries of the `ux_experiments` row that are well formed, by the same checks as
     * normaliseConfig in useExperiment.js. An entry that is not understood is left out, which is
     * control: the safe reading. A salt that is present and not 1 to 16 letters, digits, _ or - drops
     * the entry.
     *
     * @return array<string, array{arms: array<string, array{0: int, 1: int}>, until: ?float, adopted: bool, salt: string}>
     */
    private static function entries(string $raw): array
    {
        // Objects stay objects, so a JSON list is told from an object as the browser tells them.
        $decoded = json_decode($raw);
        if (! $decoded instanceof \stdClass) {
            return [];
        }

        $out = [];
        foreach (get_object_vars($decoded) as $name => $value) {
            $name = (string) $name;
            if (! preg_match(self::NAME, $name) || in_array($name, self::BLOCKED, true) || ! $value instanceof \stdClass || ! property_exists($value, 'arms')) {
                continue;
            }
            $arms = self::validArms($value->arms);
            if ($arms === null) {
                continue;
            }
            $salt = '';
            if (property_exists($value, 'salt') && $value->salt !== null) {
                if (! is_string($value->salt) || ! preg_match(self::SALT, $value->salt)) {
                    continue;
                }
                $salt = $value->salt;
            }
            $until = property_exists($value, 'until') && (is_int($value->until) || is_float($value->until)) ? (float) $value->until : null;
            $out[$name] = ['arms' => $arms, 'until' => $until, 'adopted' => ($value->adopted ?? null) === true, 'salt' => $salt];
        }

        return $out;
    }

    /**
     * @return array<string, array{0: int, 1: int}>|null
     */
    private static function validArms(mixed $arms): ?array
    {
        if (! $arms instanceof \stdClass) {
            return null;
        }
        $vars = get_object_vars($arms);
        if ($vars === [] || count($vars) > self::MAX_ARMS) {
            return null;
        }

        $out = [];
        foreach ($vars as $arm => $range) {
            $arm = (string) $arm;
            if (! preg_match(self::ARM, $arm)) {
                return null;
            }
            if (self::emptyRange($range)) {
                continue;
            }
            if (! self::validRange($range)) {
                return null;
            }
            $out[$arm] = [(int) $range[0], (int) $range[1]];
        }

        return $out === [] ? null : $out;
    }

    private static function whole(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v);
    }

    private static function validRange(mixed $r): bool
    {
        return is_array($r) && array_is_list($r) && count($r) === 2 && self::whole($r[0]) && self::whole($r[1])
            && $r[0] >= 0 && $r[1] <= 99 && $r[0] <= $r[1];
    }

    /**
     * A range written as from above to is how an arm with no share looks. It holds nobody, so it is
     * left out; it must not cost the whole entry.
     */
    private static function emptyRange(mixed $r): bool
    {
        return is_array($r) && array_is_list($r) && count($r) === 2 && self::whole($r[0]) && self::whole($r[1])
            && $r[0] >= 0 && $r[0] <= 99 && $r[1] >= -1 && $r[1] <= 99 && $r[0] > $r[1];
    }
}
