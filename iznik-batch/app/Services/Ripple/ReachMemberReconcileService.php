<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * The daily backstop for the member side of reach mail.
 *
 * The hooks that queue a member on join, move, return or frequency change live in two
 * codebases. If one is missed, or a new join path appears later without one, the members it
 * would have covered are never picked up and nothing reports it. Once a day this re-queues
 * anyone whose join or postcode change since yesterday has no reach mail ledger row after it.
 *
 * It has no containment test of its own on purpose. The drain decides whether any live reach
 * covers the member; a member nobody covers is dequeued without mail. So this pass is two
 * indexed queries against the last day and nothing heavier, and it stays cheap to keep.
 *
 * Joins and postcode changes are the two routes with table evidence to reconcile against.
 * A return after a long absence leaves none (lastaccess is overwritten by the return itself),
 * so that route relies on its hook alone.
 */
class ReachMemberReconcileService
{
    /**
     * @return array{queued: int}
     */
    public function reconcile(bool $dryRun = false): array
    {
        $since = now()->subDay();
        $queued = 0;

        // Joined since yesterday, with no reach mail since the join.
        $joined = DB::table('memberships as m')
            ->where('m.added', '>=', $since)
            ->where('m.collection', 'Approved')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('rippling_reach_notified as n')
                    ->whereColumn('n.userid', 'm.userid')
                    ->whereColumn('n.notified_at', '>=', 'm.added');
            })
            ->distinct()
            ->pluck('m.userid');

        foreach ($joined as $userid) {
            if (! $dryRun) {
                ReachMemberQueueService::enqueue((int) $userid, ReachMemberQueueService::REASON_JOINED);
            }
            $queued++;
        }

        // Changed postcode since yesterday, with no reach mail since the change.
        $moved = DB::table('logs as l')
            ->where('l.type', 'User')
            ->where('l.subtype', 'PostcodeChange')
            ->where('l.timestamp', '>=', $since)
            ->whereNotNull('l.user')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('rippling_reach_notified as n')
                    ->whereColumn('n.userid', 'l.user')
                    ->whereColumn('n.notified_at', '>=', 'l.timestamp');
            })
            ->distinct()
            ->pluck('l.user');

        foreach ($moved as $userid) {
            if (! $dryRun) {
                ReachMemberQueueService::enqueue((int) $userid, ReachMemberQueueService::REASON_MOVED);
            }
            $queued++;
        }

        return ['queued' => $queued];
    }
}
