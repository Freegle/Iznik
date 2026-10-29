<?php

namespace App\Services;

use App\Models\Membership;
use App\Services\Ripple\RippleEngagementService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Give the TN posters whose posts arrived unaddressed (messages_groups.mod_messaging_allowed = 0
 * on the post's own row) the implicit membership that ingestion now creates for such a post.
 * Posts from before that change have the flag and no membership behind it.
 *
 * Only those rows are read, live or not, and only the (poster, group) pairs they name are
 * touched. For each pair:
 *
 * - no membership: write one as ingestion does (GroupPostIngestionService::writeMembership),
 *   dated from their earliest unaddressed post there, whatever they posted to the group before.
 *   Consent lives on the membership, and a poster with none has left since: leaving and being
 *   placed back is what makes a new implicit row. Not for a banned or deleted poster, nor one
 *   with no tnuserid: the first API posts were given to the wrong account.
 * - an addressed post of theirs to the group since the membership was added is consent, as it
 *   is to ingestion: an implicit membership (TN or ripple) is cleared, with the "became
 *   ordinary" log, and a tn_unaddressed one that is not implicit stays so. A post from before
 *   the membership was added does not count: they have left since, and were placed back.
 * - a tn_unaddressed membership that is not implicit, with no such post: set implicit, unless a
 *   "became ordinary" log since it was added says it was cleared. A doubtful row ends up
 *   implicit, with contact restricted, rather than open.
 * - any other membership: leave it. An ordinary membership is never made implicit.
 *
 * It also reports the effect of deciding moderator contact per group (Phase 5). Today a TN
 * poster is restricted on every group only when all their posts are unaddressed
 * (modmessaging.UsersUnaddressedOnly): one addressed post on any group opens every group's
 * moderators to them, the mixed-poster rule. Phase 5 decides it from the poster's membership of
 * that group alone: contactable only with an ordinary (not implicit) membership there.
 *
 * About a hundred rows on production, so it reads them all in one go. Re-running finds the
 * memberships it wrote already implicit and changes nothing.
 */
class TnMembershipBackfillService
{
    public const ACTION_CREATE = 'create implicit membership';
    public const ACTION_SET_IMPLICIT = 'set implicit on tn_unaddressed membership';
    public const ACTION_CLEAR = 'clear implicit: addressed a post here since it was added';
    public const ACTION_CONSENTED = 'skip: tn_unaddressed membership consented to since it was added';
    public const ACTION_ALREADY_IMPLICIT = 'skip: tn_unaddressed membership already implicit';
    public const ACTION_CLEARED = 'skip: tn_unaddressed membership cleared since it was added';
    public const ACTION_EXISTING = 'leave existing membership';
    public const ACTION_BANNED = 'skip: banned from the group';
    public const ACTION_NO_USER = 'skip: poster deleted or missing';
    public const ACTION_NOT_LINKED = 'skip: poster has no tnuserid (stub or wrong account)';

    /**
     * @return array{
     *     pairs: list<array{userid:int, groupid:int, msgids:int[], arrival:string, action:string,
     *         membership: ?object, contact_today: bool, contact_after: bool}>,
     *     actions: array<string,int>, rows: int, mixed_posters: int,
     *     pairs_losing_contact: int, pairs_gaining_contact: int
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
            $pair['action'] = $this->actionFor($pair);

            if ($send) {
                $this->apply($pair);
            }

            $pair['contact_today'] = $this->isMixed($pair['userid']);
            if ($pair['contact_today']) {
                $mixedPosters[$pair['userid']] = true;
            }
            $pair['contact_after'] = $this->ordinaryAfter($pair);
            $actions[$pair['action']] = ($actions[$pair['action']] ?? 0) + 1;
        }
        unset($pair);

        return [
            'pairs' => $pairs,
            'actions' => $actions,
            'rows' => $rows,
            'mixed_posters' => count($mixedPosters),
            'pairs_losing_contact' => count(array_filter($pairs, fn ($p) => $p['contact_today'] && !$p['contact_after'])),
            'pairs_gaining_contact' => count(array_filter($pairs, fn ($p) => !$p['contact_today'] && $p['contact_after'])),
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

    /**
     * Today's rule, as modmessaging.UsersUnaddressedOnly reads it: an addressed post of theirs,
     * on any group, makes them contactable by every group's moderators.
     */
    private function isMixed(int $userId): bool
    {
        return $this->addressedPosts($userId)->exists();
    }

    /**
     * Consent to this membership: an addressed post of theirs to the group (own row, TN or not)
     * that arrived once the membership was there. The email path leaves the column at its
     * default of 1 and takes a post only from a member; a rippled-in copy chose nothing.
     */
    private function addressedPostSince(int $userId, int $groupId, string $added): bool
    {
        return $this->addressedPosts($userId)
            ->where('mg.groupid', $groupId)
            ->where('m.arrival', '>=', $added)
            ->exists();
    }

    /** Their posts whose own row (not a rippled-in copy) is addressed. */
    private function addressedPosts(int $userId): Builder
    {
        return DB::table('messages AS m')
            ->join('messages_groups AS mg', 'mg.msgid', '=', 'm.id')
            ->where('m.fromuser', $userId)
            ->where('mg.rippled_in', 0)
            ->where('mg.mod_messaging_allowed', 1);
    }

    private function actionFor(array $pair): string
    {
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

        $tnUnaddressed = $membership->added_origin === Membership::ADDED_ORIGIN_TN_UNADDRESSED;
        $consented = $this->addressedPostSince($pair['userid'], $pair['groupid'], $membership->added);

        // Either flag: a ripple join made before 2a's last sync can have rippled = 1 and
        // implicit = 0, and RippleEngagementService::clear clears both.
        if ($membership->rippled || $membership->implicit) {
            if ($consented) {
                return self::ACTION_CLEAR;
            }

            return $tnUnaddressed ? self::ACTION_ALREADY_IMPLICIT : self::ACTION_EXISTING;
        }

        if (!$tnUnaddressed) {
            return self::ACTION_EXISTING;
        }

        if ($consented) {
            return self::ACTION_CONSENTED;
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
        } elseif ($pair['action'] === self::ACTION_CLEAR) {
            app(RippleEngagementService::class)->clear($pair['userid'], $pair['groupid'],
                GroupPostIngestionService::REASON_CONSENTED);
        } elseif ($pair['action'] === self::ACTION_SET_IMPLICIT) {
            DB::table('memberships')
                ->where('id', $pair['membership']->id)
                ->where('implicit', 0)
                ->update(['implicit' => 1]);
        }
    }

    /**
     * Whether the pair has an ordinary membership once this run's action is taken, which is
     * what Phase 5 lets that group's moderators contact. A ripple membership is implicit
     * whichever of the two flags says so.
     */
    private function ordinaryAfter(array $pair): bool
    {
        $membership = $pair['membership'];
        if ($membership === null || $pair['action'] === self::ACTION_SET_IMPLICIT) {
            return false;
        }

        return $pair['action'] === self::ACTION_CLEAR || (!$membership->rippled && !$membership->implicit);
    }
}
