<?php

namespace App\Services\TrashNothing\Ingestion;

use Illuminate\Support\Facades\Cache;

/**
 * Why TN posts ingestion skipped for want of a user, remembered so that tn:verify-email-coverage
 * can tell them from posts that went missing.
 *
 * Ingestion decided about each of these posts, so no message is made and `messages.tnpostid`
 * never exists for it. They are not all alike:
 *
 *  - EXPECTED_REASONS are the skips nobody needs to look at: a poster banned from the group, and a
 *    post TN gave no user id for. The verifier counts such a post as "skipped on purpose", not a miss.
 *  - NEEDS_A_HUMAN_REASONS are skips that only a person can put right (an address or a TN user id
 *    still held by a deleted account). Each is still a miss, so
 *    that it is reported and can be backfilled once someone has fixed the cause, and the first one of
 *    the day for each reason is sent to Sentry (GroupPostIngestionService).
 *  - The rest (a poster TN does not know, a lookup TN refused) are misses too. TN's answer may have
 *    been wrong or may change, and a miss is what lets the verifier ask again.
 *
 * The note names the reason for every one of them, and the verifier shows it beside each miss. A
 * failure to ask TN who the poster is leaves no note: nothing was decided, and the post stays a miss.
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

    /**
     * Skips that are not a miss. Anything else noted is.
     */
    public const EXPECTED_REASONS = [
        GroupPostIngestionService::REASON_BANNED,
        GroupPostIngestionService::REASON_NO_USER_ID,
    ];

    /**
     * Skips a person has to put right.
     */
    public const NEEDS_A_HUMAN_REASONS = [
        GroupPostIngestionService::REASON_TN_ADDRESS_HELD_BY_DELETED_USER,
        GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED,
    ];

    public static function record(string $tnPostId, string $reason): void
    {
        Cache::put(self::KEY_PREFIX . $tnPostId, $reason, now()->addDays(self::TTL_DAYS));
    }

    /**
     * Why ingestion skipped this TN post, or null if it left no note.
     */
    public static function reasonFor(string $tnPostId): ?string
    {
        $reason = Cache::get(self::KEY_PREFIX . $tnPostId);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Whether a post skipped for this reason is not a miss.
     */
    public static function isExpected(string $reason): bool
    {
        return in_array($reason, self::EXPECTED_REASONS, true);
    }

    /**
     * Whether a post skipped for this reason waits for a person.
     */
    public static function needsAHuman(string $reason): bool
    {
        return in_array($reason, self::NEEDS_A_HUMAN_REASONS, true);
    }
}
