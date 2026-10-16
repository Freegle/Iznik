<?php

namespace App\Services\TrashNothing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether a TN poster ever agreed to hear from Freegle.
 *
 * A TN post placed on a Freegle community the poster never chose is "unaddressed": its
 * origin messages_groups row carries mod_messaging_allowed = 0 (see PostSyncer). Someone
 * whose only presence on Freegle is posts like that has not opted in to Freegle at all,
 * so nothing here may write to them - not moderators, and not Freegle's own mail.
 *
 * Mirrors iznik-server-go/modmessaging, which is where the interactive paths enforce the
 * same rule. Keep the two in step.
 */
class ModMessaging
{
    /**
     * Whether a post is a TN post that was never addressed to the Freegle community it
     * landed on, and so has a poster no moderator here may write to.
     *
     * The origin row (rippled_in = 0) is the one that carries the answer: the rippling
     * engine inserts its copies without the column, so they take the table default
     * (allowed) and would mask it. False for a missing id, the safe direction, since
     * everything this gates only ever removes the ability to write to someone.
     */
    public static function postIsUnaddressed(int $msgId): bool
    {
        if ($msgId <= 0) {
            return false;
        }

        return DB::table('messages_groups')
            ->where('msgid', $msgId)
            ->where('rippled_in', 0)
            ->where('mod_messaging_allowed', 0)
            ->exists();
    }

    /**
     * Whether every post this person has made is unaddressed - so they have not opted in
     * to Freegle and cannot be written to.
     *
     * A "mixed" poster (one such post AND an ordinary one) is a real member and is not
     * restricted. Deleted posts still count, as in Go: a post taken down has not made its
     * poster contactable. Someone who has never posted answers false and is unrestricted.
     */
    public static function userIsUnaddressedOnly(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // Two questions, not one aggregate: has this person any post of that kind, and
        // have they any post that is NOT of that kind.
        $originRows = fn () => DB::table('messages as m')
            ->join('messages_groups as mg', function ($join) {
                $join->on('mg.msgid', '=', 'm.id')->where('mg.rippled_in', 0);
            })
            ->where('m.fromuser', $userId);

        if (!$originRows()->where('mg.mod_messaging_allowed', 0)->exists()) {
            return false;
        }

        return !$originRows()->where('mg.mod_messaging_allowed', 1)->exists();
    }

    /**
     * Whether this person's only tie to ONE community is TN posts placed there without
     * their choosing: at least one unaddressed post on it, and no addressed one.
     *
     * This is what the silent membership GroupPostIngestionService makes stands for, and
     * it is the TN equivalent of a ripple-join (memberships.rippled = 1), so it is barred
     * from whatever that is barred from. Unlike userIsUnaddressedOnly() it is per
     * community: a "mixed" poster is a real member where they chose to be, and only there.
     * Deleted posts still count, for the same reason as there. False for a missing id.
     */
    public static function isUnaddressedOn(int $userId, int $groupId): bool
    {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }

        $posts = fn (int $allowed) => self::originPosts(DB::query(), $allowed)
            ->where('mm_msg.fromuser', $userId)
            ->where('mm_mg.groupid', $groupId)
            ->exists();

        return $posts(0) && !$posts(1);
    }

    /**
     * Drop the rows isUnaddressedOn() would answer true for from a query that selects
     * members - by default one over `memberships`. The columns are compared as columns,
     * so pass column names, never input.
     *
     * Written as its negation, NOT EXISTS (unaddressed) OR EXISTS (addressed), and driven
     * from the user's own posts (messages.fromuser), so it is a few keyed probes per row
     * however big the community is.
     */
    public static function excludeUnaddressedMemberships(
        Builder $query,
        string $userColumn = 'memberships.userid',
        string $groupColumn = 'memberships.groupid',
    ): Builder {
        $posts = fn (Builder $sub, int $allowed) => self::originPosts($sub, $allowed)
            ->whereColumn('mm_msg.fromuser', $userColumn)
            ->whereColumn('mm_mg.groupid', $groupColumn);

        return $query->where(fn (Builder $q) => $q
            ->whereNotExists(fn (Builder $sub) => $posts($sub, 0))
            ->orWhereExists(fn (Builder $sub) => $posts($sub, 1)));
    }

    /**
     * A person's posts by the mod_messaging_allowed of their ORIGIN row, aliased so they
     * cannot collide with the caller's own tables.
     */
    private static function originPosts(Builder $query, int $allowed): Builder
    {
        return $query->from('messages as mm_msg')
            ->join('messages_groups as mm_mg', function ($join) {
                $join->on('mm_mg.msgid', '=', 'mm_msg.id')->where('mm_mg.rippled_in', 0);
            })
            ->where('mm_mg.mod_messaging_allowed', $allowed)
            ->select('mm_mg.msgid');
    }
}
