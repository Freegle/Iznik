<?php

namespace App\Services\Automod;

/**
 * The site-wide quality-check sample: a deterministic share of otherwise-clean posts held in
 * Pending for a moderator's verdict, so the sample's error rate can be set against the
 * auto-published population's. Deterministic on msgid, so a post never oscillates between
 * runs, and shared by everything that needs to agree on it: AutomodService stamps
 * messages_groups.quality_sample as it records an approve (so the Pending card can say so
 * from the start), AutoApproveCleanService skips stamped rows, and the Go countdown reads
 * the stamp.
 */
final class QualitySample
{
    public static function percent(): int
    {
        return (int) config('freegle.autoapprove.quality_check_percent', 0);
    }

    public static function sampled(int $msgid): bool
    {
        $percent = self::percent();
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        return (abs(crc32((string) $msgid)) % 100) < $percent;
    }
}
