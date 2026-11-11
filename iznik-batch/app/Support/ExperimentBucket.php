<?php

namespace App\Support;

/**
 * Deterministic, stable-per-user experiment bucketing.
 *
 * Uses CRC32 (the same choice UnifiedDigestService made for worker sharding,
 * because it spreads uniformly for any modulus and doesn't skew when the bucket
 * count shares a factor with clustering in the id space). The input is salted
 * with the experiment name so two independent experiments don't move in
 * lockstep for the same user.
 *
 * Stable means: re-computing for the same (userId, experiment) always yields the
 * same bucket, so a user stays in the same arm across the whole 3-stage
 * re-engagement sequence with no lookup table required for reproducibility (we
 * still persist the resolved arm/bucket per send for auditability).
 *
 * The UX experiments (docs/developers/reference/ux-experiments.md) use the same function in three
 * places, which must agree: the browser (iznik-nuxt3/composables/useExperiment.js), this class and
 * iznik-server-go/ux/serverarm.go. An optional salt is appended to the text, which gives a re-run of
 * an experiment different buckets from the run before it.
 */
final class ExperimentBucket
{
    /**
     * @return int 0..($buckets-1)
     */
    public static function bucket(int $userId, string $experiment, int $buckets = 100, string $salt = ''): int
    {
        // crc32 returns an unsigned 32-bit int in PHP; modulo is safe and stable.
        return crc32($userId . '|' . $experiment . ($salt !== '' ? '|' . $salt : '')) % $buckets;
    }

    /**
     * The arm whose inclusive [from, to] range holds the member's bucket, or null when none does.
     * The first arm that holds it wins. A range with from above to holds nobody, which is how an
     * arm with no share is written.
     *
     * @param  array<string, array{0:int,1:int}>  $ranges  arm => [from, to]
     */
    public static function armIn(int $userId, string $name, array $ranges, string $salt = ''): ?string
    {
        $bucket = self::bucket($userId, $name, 100, $salt);

        foreach ($ranges as $arm => $range) {
            if (! is_array($range) || count($range) !== 2) {
                continue;
            }
            [$from, $to] = array_values($range);
            if ($bucket >= $from && $bucket <= $to) {
                return (string) $arm;
            }
        }

        return null;
    }

    /**
     * Resolve a user's arm from a config arm-map of the form
     *   ['control' => ['from' => 0, 'to' => 19], 'a' => ['from' => 20, 'to' => 59], ...]
     * where from/to are inclusive percentile bounds over 0..99.
     *
     * @param array<string, array{from:int,to:int}> $arms
     * @return array{arm: string, bucket: int}
     */
    public static function resolveArm(int $userId, string $experiment, array $arms): array
    {
        $bucket = self::bucket($userId, $experiment, 100);

        foreach ($arms as $arm => $range) {
            $from = (int) ($range['from'] ?? 0);
            $to = (int) ($range['to'] ?? -1);
            if ($bucket >= $from && $bucket <= $to) {
                return ['arm' => (string) $arm, 'bucket' => $bucket];
            }
        }

        // Bucket fell outside every configured range (arms don't cover 0-99).
        // Fail safe: no arm (caller treats as "not in experiment").
        return ['arm' => '', 'bucket' => $bucket];
    }
}
