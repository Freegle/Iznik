<?php

namespace App\Services\AzureArchive;

/**
 * Reading a safety switch that is on unless someone deliberately turns it off.
 *
 * Laravel's env() turns the string "false" into a real false before anything else sees it, so a
 * cast to string gives "" and a check for "false" misses it. Only false, 0, "false" and "0" turn
 * the switch off; anything else, a typo included, leaves it on, which is the safe way round.
 */
final class ArchiveSwitch
{
    public static function onUnlessFalse(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return ! in_array(strtolower(trim((string) $value)), ['false', '0'], true);
    }
}
