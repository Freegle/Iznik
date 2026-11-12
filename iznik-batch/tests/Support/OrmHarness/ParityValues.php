<?php

namespace Tests\Support\OrmHarness;

/**
 * Turns what a site returned into a form two engines can be compared in. Pure: no database, no
 * framework. The Go harness (iznik-server-go/parity/values.go) does the same for the same reasons.
 *
 * The engines hand PHP different types for the same data, and none of those differences means the
 * rows differ:
 *
 *  - a boolean is 1 or 0: MySQL has no boolean, so a comparison or EXISTS() is 1 or 0 there and
 *    true or false on PostgreSQL
 *  - a float with no fraction is that integer
 *  - a time written with a zone is the same instant in UTC, and a time without one is kept as
 *    written
 *  - an object (a row from DB::select) is an array
 *
 * NULL stays NULL and is never equal to 0 or to an empty string. Text is compared exactly, so a
 * case difference is a difference: the baseline made the columns the code relies on
 * case-insensitive with citext, and this is what shows one that was missed. A number and the text
 * of that number stay different, because the two engines do return one where the other returns the
 * other, and a site that compares with === sees it.
 */
final class ParityValues
{
    private const TIME_WITH_ZONE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}(:?\d{2})?)$/';

    private const TIME_WITHOUT_ZONE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?$/';

    public static function normalise(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return self::normaliseFloat($v);
        }
        if (is_string($v)) {
            return self::normaliseText($v);
        }
        if ($v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        }
        if ($v instanceof \Illuminate\Support\Collection) {
            return self::normalise($v->all());
        }
        if ($v instanceof \JsonSerializable) {
            return self::normalise($v->jsonSerialize());
        }
        if (is_object($v)) {
            return self::normalise(get_object_vars($v));
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = self::normalise($item);
            }

            return $out;
        }

        return (string) $v;
    }

    private static function normaliseFloat(float $f): int|float
    {
        if ($f === floor($f) && abs($f) < 9007199254740992.0) {
            return (int) $f;
        }

        return $f;
    }

    private static function normaliseText(string $s): string
    {
        if (preg_match(self::TIME_WITH_ZONE, $s) === 1) {
            try {
                return (new \DateTimeImmutable($s))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u');
            } catch (\Exception) {
                return $s;
            }
        }
        if (preg_match(self::TIME_WITHOUT_ZONE, $s) === 1) {
            try {
                return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            } catch (\Exception) {
                return $s;
            }
        }

        return $s;
    }

    /**
     * True when a normalised result carries no information: no rows, no value, zero or an empty
     * string. Two engines agreeing on nothing prove nothing.
     */
    public static function isEmpty(mixed $n): bool
    {
        return $n === null || $n === [] || $n === 0 || $n === 0.0 || $n === '';
    }

    /**
     * Puts the rows of a normalised list in a fixed order, for a query with no ORDER BY.
     */
    public static function sortUnordered(mixed $n): mixed
    {
        if (! is_array($n) || ! array_is_list($n)) {
            return $n;
        }
        usort($n, fn ($a, $b) => self::key($a) <=> self::key($b));

        return $n;
    }

    /**
     * Where two normalised values first differ, as a path, or '' when they are equal.
     */
    public static function firstDifference(mixed $a, mixed $b, string $path = 'result'): string
    {
        if (is_array($a) && is_array($b)) {
            $keys = array_values(array_unique(array_merge(array_keys($a), array_keys($b)), SORT_REGULAR));
            if (array_is_list($a) && array_is_list($b)) {
                $n = min(count($a), count($b));
                for ($i = 0; $i < $n; $i++) {
                    $d = self::firstDifference($a[$i], $b[$i], "{$path}[{$i}]");
                    if ($d !== '') {
                        return $d;
                    }
                }

                return count($a) === count($b) ? '' : sprintf('%s: %d rows against %d rows', $path, count($a), count($b));
            }
            sort($keys);
            foreach ($keys as $k) {
                if (! array_key_exists($k, $a)) {
                    return sprintf('%s.%s: missing against %s', $path, $k, self::show($b[$k]));
                }
                if (! array_key_exists($k, $b)) {
                    return sprintf('%s.%s: %s against missing', $path, $k, self::show($a[$k]));
                }
                $d = self::firstDifference($a[$k], $b[$k], "{$path}.{$k}");
                if ($d !== '') {
                    return $d;
                }
            }

            return '';
        }

        return $a === $b ? '' : sprintf('%s: %s against %s', $path, self::show($a), self::show($b));
    }

    public static function show(mixed $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = $json === false ? get_debug_type($v) : $json;
        if (strlen($json) > 300) {
            $json = substr($json, 0, 300).'...';
        }

        return sprintf('%s (%s)', $json, get_debug_type($v));
    }

    private static function key(mixed $row): string
    {
        return (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
