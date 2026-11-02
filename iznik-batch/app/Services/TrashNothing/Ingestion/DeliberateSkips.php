<?php

namespace App\Services\TrashNothing\Ingestion;

use Illuminate\Support\Facades\Cache;

/**
 * The TN posts ingestion skipped on purpose, remembered so that tn:verify-email-coverage can
 * tell them from posts that went missing.
 *
 * A post is skipped on purpose when ingestion decided about it and the decision is final: a
 * banned poster, a poster TN does not know, a username clash, a deleted account. No message is
 * made, so `messages.tnpostid` never exists for it, and the verifier would count it as a miss
 * on every run until the email archive forgets it. A failure to ask TN who the poster is is
 * not on purpose: nothing was decided, the post is still wanted, and it stays a miss.
 *
 * It is a cache entry rather than a table. It only has to outlive the verifier's view of the
 * post, and losing it (a cache flush) costs nothing worse than the old behaviour: the post is
 * reported as a miss again and is skipped again if it is backfilled.
 */
class DeliberateSkips
{
    private const KEY_PREFIX = 'tn-ingest:deliberate-skip:';

    /**
     * The verifier can look no further back than the incoming mail archive is kept (48 hours,
     * mail:cleanup-archive), so a skip is kept for longer than that.
     */
    public const TTL_DAYS = 3;

    public static function record(string $tnPostId, string $reason): void
    {
        Cache::put(self::KEY_PREFIX . $tnPostId, $reason, now()->addDays(self::TTL_DAYS));
    }

    /**
     * Why ingestion skipped this TN post on purpose, or null if it did not.
     */
    public static function reasonFor(string $tnPostId): ?string
    {
        $reason = Cache::get(self::KEY_PREFIX . $tnPostId);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
