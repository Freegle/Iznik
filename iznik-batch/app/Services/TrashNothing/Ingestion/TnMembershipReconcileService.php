<?php

namespace App\Services\TrashNothing\Ingestion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives a TN API post's poster the membership ingestion should have written for it, when it is
 * missing. Ingestion writes the membership after the post, and a failure there is reported but
 * the post is kept, so the poster can be left with no membership: the post is then never
 * auto-approved, and moderators of a group the poster chose cannot contact them.
 *
 * Reads the own (not rippled-in), undeleted group rows of TN API posts that arrived in the last
 * WINDOW_DAYS. A pair is repaired only when the poster has no membership of the group, is not
 * banned from it and has not left it since the post: someone who left chose to, and is not put
 * back. The membership is written as ingestion would (GroupPostIngestionService::writeMembership),
 * ordinary for a group the poster chose and a placement for one they did not, dated from the post.
 *
 * Each repair means a write failed somewhere, so each is reported to Sentry.
 */
class TnMembershipReconcileService
{
    public const WINDOW_DAYS = 7;

    /**
     * @return array{missing: int, repaired: int, failed: int}
     */
    public function reconcile(bool $send): array
    {
        $stats = ['missing' => 0, 'repaired' => 0, 'failed' => 0];

        foreach ($this->missing() as $row) {
            $stats['missing']++;

            if (!$send) {
                continue;
            }

            try {
                if (GroupPostIngestionService::writeMembership((int) $row->userid, (int) $row->groupid,
                    (bool) $row->chosen, new \DateTimeImmutable($row->arrival))) {
                    $stats['repaired']++;
                    GroupPostIngestionService::reportFailure('TN post had no membership behind it; written now',
                        new \RuntimeException("TN membership missing: user {$row->userid}, group {$row->groupid}"),
                        ['user_id' => $row->userid, 'group_id' => $row->groupid, 'msgid' => $row->msgid]);
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                GroupPostIngestionService::reportFailure('TN membership repair failed', $e,
                    ['user_id' => $row->userid, 'group_id' => $row->groupid, 'msgid' => $row->msgid]);
            }
        }

        Log::info('tn:reconcile-memberships', $stats + ['send' => $send]);

        return $stats;
    }

    /**
     * One row per (poster, group) with no membership behind it: the earliest such post.
     */
    private function missing(): \Illuminate\Support\Collection
    {
        return DB::table('messages AS m')
            ->join('messages_groups AS mg', 'mg.msgid', '=', 'm.id')
            ->join('users AS u', 'u.id', '=', 'm.fromuser')
            ->where('m.sourceheader', GroupPostIngestionService::SOURCEHEADER)
            ->where('m.arrival', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('mg.rippled_in', 0)
            ->where('mg.deleted', 0)
            ->whereNull('u.deleted')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('memberships AS mem')
                ->whereColumn('mem.userid', 'm.fromuser')->whereColumn('mem.groupid', 'mg.groupid'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users_banned AS b')
                ->whereColumn('b.userid', 'm.fromuser')->whereColumn('b.groupid', 'mg.groupid'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('logs AS l')
                ->whereColumn('l.user', 'm.fromuser')->whereColumn('l.groupid', 'mg.groupid')
                ->where('l.type', 'Group')->where('l.subtype', 'Left')
                ->whereColumn('l.timestamp', '>=', 'm.arrival'))
            ->groupBy('m.fromuser', 'mg.groupid')
            ->orderBy('m.fromuser')
            ->get([
                'm.fromuser AS userid',
                'mg.groupid',
                DB::raw('MIN(m.id) AS msgid'),
                DB::raw('MIN(m.arrival) AS arrival'),
                // Chosen if any of their posts there chose it, as ingestion's consent would have
                // made the membership ordinary.
                DB::raw('MAX(mg.mod_messaging_allowed) AS chosen'),
            ]);
    }
}
