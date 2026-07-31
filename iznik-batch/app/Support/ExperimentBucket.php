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
 */
final class ExperimentBucket
{
    /**
     * @return int 0..($buckets-1)
     */
    public static function bucket(int $userId, string $experiment, int $buckets = 100): int
    {
        // crc32 returns an unsigned 32-bit int in PHP; modulo is safe and stable.
        return crc32($userId . '|' . $experiment) % $buckets;
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
