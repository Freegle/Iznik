<?php

namespace App\Services;

use App\Models\Membership;
use App\Services\Ripple\RippleEngagementService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Support\Facades\DB;

/**
 * Give the TN posters whose posts arrived unaddressed (messages_groups.mod_messaging_allowed = 0
 * on the post's own row) the implicit membership that ingestion now creates for such a post.
 * Posts from before that change have the flag and no membership behind it.
 *
 * Only those rows are read, live or not, and only the (poster, group) pairs they name are
 * touched. For each pair:
 *
 * - another post of theirs on that group was addressed: skip. They consented to it once, and
 *   consent only goes one way. A TN post that came in by email counts: the email path refuses a
 *   post to a group the poster has not joined, and leaves the column at its default of 1.
 * - no membership: write one as ingestion does (GroupPostIngestionService::writeMembership),
 *   dated from their earliest unaddressed post there. Not for a banned or deleted poster, nor
 *   one with no tnuserid: the first API posts were given to the wrong account.
 * - a tn_unaddressed membership that is not implicit: set implicit, unless a "became ordinary"
 *   log since it was added says it was cleared on purpose.
 * - any other membership: leave it. An ordinary membership is never made implicit.
 *
 * About a hundred rows on production, so it reads them all in one go. Re-running finds the
 * memberships it wrote already implicit and changes nothing.
 */
class TnMembershipBackfillService
{
    public const ACTION_CREATE = 'create implicit membership';
    public const ACTION_SET_IMPLICIT = 'set implicit on tn_unaddressed membership';
    public const ACTION_ALREADY_IMPLICIT = 'skip: tn_unaddressed membership already implicit';
    public const ACTION_CLEARED = 'skip: tn_unaddressed membership cleared since it was added';
    public const ACTION_CONSENTED = 'skip: addressed another post to this group';
    public const ACTION_EXISTING = 'leave existing membership';
    public const ACTION_BANNED = 'skip: banned from the group';
    public const ACTION_NO_USER = 'skip: poster deleted or missing';
    public const ACTION_NOT_LINKED = 'skip: poster has no tnuserid (stub or wrong account)';

    /**
     * @return array{
     *     pairs: list<array{userid:int, groupid:int, msgids:int[], arrival:string, action:string,
     *         membership: ?object, mixed: bool, losing_contact: bool}>,
     *     actions: array<string,int>, rows: int, mixed_posters: int, pairs_losing_contact: int
     * }
     */
    public function backfill(bool $send): array
    {
        $pairs = $this->unaddressedPairs();
        $actions = [];
        $mixedPosters = [];
        $rows = 0;

        foreach ($pairs as &$pair) {
            $rows += count($pair['msgids']);
            $pair['membership'] = DB::table('memberships')
                ->where('userid', $pair['userid'])
                ->where('groupid', $pair['groupid'])
                ->first(['id', 'rippled', 'implicit', 'added_origin', 'added']);
            $pair['consented'] = $this->addressedPostExists($pair['userid'], $pair['groupid']);
            $pair['action'] = $this->actionFor($pair);

            if ($send) {
                $this->apply($pair);
            }

            $pair['mixed'] = $this->addressedPostExists($pair['userid'], null);
            if ($pair['mixed']) {
                $mixedPosters[$pair['userid']] = true;
            }
            $pair['losing_contact'] = $pair['mixed'] && !$pair['consented'] && !$this->ordinaryAfter($pair);
            $actions[$pair['action']] = ($actions[$pair['action']] ?? 0) + 1;
        }
        unset($pair);

        return [
            'pairs' => $pairs,
            'actions' => $actions,
            'rows' => $rows,
            'mixed_posters' => count($mixedPosters),
            'pairs_losing_contact' => count(array_filter($pairs, fn ($p) => $p['losing_contact'])),
        ];
    }

    /**
     * The unaddressed post rows, grouped by (poster, group). Driven from messages.tnpostid,
     * which is indexed: only TN ingestion writes mod_messaging_allowed = 0, and that column
     * has no index.
     */
    private function unaddressedPairs(): array
    {
        $rows = DB::table('messages AS m')
            ->join('messages_groups AS mg', 'mg.msgid', '=', 'm.id')
            ->whereNotNull('m.tnpostid')
            ->whereNotNull('m.fromuser')
            ->where('mg.rippled_in', 0)
            ->where('mg.mod_messaging_allowed', 0)
            ->orderBy('m.fromuser')->orderBy('mg.groupid')->orderBy('m.arrival')
            ->get(['m.fromuser', 'mg.groupid', 'm.id', 'm.arrival']);

        $pairs = [];
        foreach ($rows as $row) {
            $key = $row->fromuser.':'.$row->groupid;
            // Ordered by arrival, so the first row seen is the earliest post.
            $pairs[$key] ??= [
                'userid' => (int) $row->fromuser,
                'groupid' => (int) $row->groupid,
                'msgids' => [],
                'arrival' => $row->arrival,
            ];
            $pairs[$key]['msgids'][] = (int) $row->id;
        }

        return array_values($pairs);
    }

    /** An addressed post of theirs on $groupId, or on any group when it is null. */
    private function addressedPostExists(int $userId, ?int $groupId): bool
    {
        return DB::table('messages AS m')
            ->join('messages_groups AS mg', 'mg.msgid', '=', 'm.id')
            ->where('m.fromuser', $userId)
            ->where('mg.rippled_in', 0)
            ->where('mg.mod_messaging_allowed', 1)
            ->when($groupId !== null, fn ($q) => $q->where('mg.groupid', $groupId))
            ->exists();
    }

    private function actionFor(array $pair): string
    {
        if ($pair['consented']) {
            return self::ACTION_CONSENTED;
        }

        $membership = $pair['membership'];
        if ($membership === null) {
            $user = DB::table('users')->where('id', $pair['userid'])->whereNull('deleted')->first(['tnuserid']);
            if ($user === null) {
                return self::ACTION_NO_USER;
            }

            // Every poster ingestion resolves has one: it finds them through users.tnuserid.
            // Before 1c7ac72deb it took TN's id for ours, and handed the post to whichever
            // account had that id or to a stub made with it. Neither is the poster.
            if ($user->tnuserid === null) {
                return self::ACTION_NOT_LINKED;
            }

            // A banned poster's row was deleted by the ban; never put it back.
            return DB::table('users_banned')->where('userid', $pair['userid'])->where('groupid', $pair['groupid'])->exists()
                ? self::ACTION_BANNED
                : self::ACTION_CREATE;
        }

        if ($membership->added_origin !== Membership::ADDED_ORIGIN_TN_UNADDRESSED) {
            return self::ACTION_EXISTING;
        }

        if ($membership->implicit) {
            return self::ACTION_ALREADY_IMPLICIT;
        }

        $cleared = DB::table('logs')
            ->where('user', $pair['userid'])
            ->where('groupid', $pair['groupid'])
            ->where('type', 'Group')
            ->where('subtype', 'Joined')
            ->where('text', 'LIKE', RippleEngagementService::LOG_PREFIX.'%')
            ->where('timestamp', '>=', $membership->added)
            ->exists();

        return $cleared ? self::ACTION_CLEARED : self::ACTION_SET_IMPLICIT;
    }

    private function apply(array $pair): void
    {
        if ($pair['action'] === self::ACTION_CREATE) {
            GroupPostIngestionService::writeMembership($pair['userid'], $pair['groupid'], false,
                new \DateTimeImmutable($pair['arrival']));
        } elseif ($pair['action'] === self::ACTION_SET_IMPLICIT) {
            DB::table('memberships')
                ->where('id', $pair['membership']->id)
                ->where('implicit', 0)
                ->update(['implicit' => 1]);
        }
    }

    /** Whether the pair has an ordinary membership once this run's action is taken. */
    private function ordinaryAfter(array $pair): bool
    {
        $membership = $pair['membership'];

        return $membership !== null
            && !$membership->rippled
            && !$membership->implicit
            && $pair['action'] !== self::ACTION_SET_IMPLICIT;
    }
}
