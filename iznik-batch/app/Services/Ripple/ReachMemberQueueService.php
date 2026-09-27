<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * The member side of reach mail.
 *
 * A post's reach changing is one trigger for reach mail; a member changing is the
 * other. This queue carries the second. The codepath that changes a member writes
 * a row (most of them are in iznik-server-go, which writes the same table directly),
 * and UnifiedDigestService's reach pass drains it, asking which live posts now cover
 * the member and mailing them about each.
 *
 * One row per member: the UNIQUE key on userid means a burst of signals before the
 * drain collapses to one row carrying the latest reason.
 */
class ReachMemberQueueService
{
    public const REASON_JOINED = 'joined';
    public const REASON_MOVED = 'moved';
    public const REASON_RETURNED = 'returned';
    public const REASON_FREQUENCY = 'frequency';

    public static function enqueue(int $userid, string $reason): void
    {
        DB::table('rippling_reach_member_pending')->upsert(
            ['userid' => $userid, 'reason' => $reason, 'added' => now()],
            ['userid'],
            ['reason', 'added']
        );
    }
}
