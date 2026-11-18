<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * The member side of reach mail.
 *
 * A post's reach changing is one trigger for reach mail; a member changing is the
 * other. This queue carries the second. The codepath that changes a member writes
 * a row (most of them are in iznik-server-go, which writes the same table directly),
 * and UnifiedDigestService's reach pass drains it, asking which live posts whose reach
 * changed in the last hour now cover the member and mailing them about each.
 *
 * One row per member: the UNIQUE key on userid means a burst of signals before the
 * drain collapses to one row carrying the latest reason, except that a join never
 * replaces another reason.
 */
class ReachMemberQueueService
{
    public const REASON_JOINED = 'joined';
    public const REASON_MOVED = 'moved';
    public const REASON_RETURNED = 'returned';
    public const REASON_FREQUENCY = 'frequency';

    /**
     * @param \DateTimeInterface|string|null $at When the member changed, if that is not now. Reach
     *     mail leaves out posts from before this time, so a change found late is queued with the
     *     time it happened.
     */
    public static function enqueue(int $userid, string $reason, \DateTimeInterface|string|null $at = null): void
    {
        // A join never replaces another reason: the drain records a move, a return or a
        // switch to immediate mail against the member, and a join needs nothing recorded.
        // The time only moves forward. iznik-server-go's reachqueue writes the same rule.
        // keep-raw: an upsert cannot make its update depend on the row's present values.
        DB::statement(
            "INSERT INTO rippling_reach_member_pending (userid, reason, added) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 reason = IF(VALUES(reason) = 'joined', reason, VALUES(reason)),
                 added = GREATEST(added, VALUES(added))",
            [$userid, $reason, $at ?? now()]
        );
    }
}
