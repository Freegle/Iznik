<?php

namespace App\Services\Concerns;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Shared plumbing for the user-centric "roundup" digests (community events and
 * volunteering opportunities).
 *
 * Both roundups now send ONE combined email per user covering every group they
 * belong to — instead of a separate per-group email — with the same item
 * (cross-posted to several of the user's groups) shown once. The user/group
 * eligibility, the per-user cadence guard and the "which of my groups is this
 * shared with" attribution are identical between the two, so they live here.
 */
trait BuildsUserRoundups
{
    /** Minimum days between roundups of a given kind for one user (V1: DATEDIFF >= 3). */
    public const MIN_INTERVAL_DAYS = 3;

    /**
     * Freegle groups eligible for a roundup of the given kind, keyed id => nameshort.
     *
     * Mirrors the per-group eligibility the old per-group services applied:
     * published, on-here Freegle groups that aren't playgrounds, aren't closed,
     * and have the relevant feature setting enabled (default on).
     *
     * @param string $settingKey 'communityevents' | 'volunteering'
     * @return array<int,string>
     */
    protected function eligibleGroups(string $settingKey): array
    {
        $candidates = Group::query()
            ->where('type', Group::TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->whereRaw("nameshort NOT LIKE '%playground%'")
            ->get(['id', 'nameshort', 'settings']);

        $eligible = [];
        foreach ($candidates as $group) {
            if (!$group->getSetting($settingKey, true)) {
                continue;
            }
            if ($group->isClosed()) {
                continue;
            }
            $eligible[(int) $group->id] = $group->nameshort;
        }

        return $eligible;
    }

    /**
     * Stream users eligible for a roundup: deliverable (active / not on holiday /
     * not bouncing / simplemail != None), with at least one approved membership
     * in an eligible group that has the relevant per-group flag set and a
     * non-zero email frequency, and not already sent this roundup type within
     * MIN_INTERVAL_DAYS.
     *
     * @param array<int> $groupIds      Eligible group ids.
     * @param string     $allowedColumn 'eventsallowed' | 'volunteeringallowed'.
     * @param string     $mode          users_digests.mode ('events' | 'volunteering').
     */
    protected function eligibleUsers(array $groupIds, string $allowedColumn, string $mode): LazyCollection
    {
        if (empty($groupIds)) {
            return User::query()->whereRaw('1 = 0')->lazyById(500);
        }

        return User::query()
            ->select(['users.id'])
            ->whereExists(function ($q) use ($groupIds, $allowedColumn) {
                $q->select(DB::raw(1))
                    ->from('memberships')
                    ->whereColumn('memberships.userid', 'users.id')
                    ->whereIn('memberships.groupid', $groupIds)
                    ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                    ->where("memberships.$allowedColumn", 1)
                    ->where('memberships.emailfrequency', '!=', 0);
            })
            ->whereNotExists(function ($q) use ($mode) {
                $q->select(DB::raw(1))
                    ->from('users_digests')
                    ->whereColumn('users_digests.userid', 'users.id')
                    ->where('users_digests.mode', $mode)
                    ->where('users_digests.lastsent', '>', now()->subDays(self::MIN_INTERVAL_DAYS));
            })
            ->receivingOurMails()
            ->lazyById(500);
    }

    /**
     * The subset of a user's approved memberships (with the relevant flag set and
     * non-zero frequency) that fall within the eligible group set.
     *
     * @param array<int> $eligibleGroupIds
     * @return array<int>
     */
    protected function userGroupIds(int $userId, array $eligibleGroupIds, string $allowedColumn): array
    {
        if (empty($eligibleGroupIds)) {
            return [];
        }

        return DB::table('memberships')
            ->where('userid', $userId)
            ->whereIn('groupid', $eligibleGroupIds)
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->where($allowedColumn, 1)
            ->where('emailfrequency', '!=', 0)
            ->pluck('groupid')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Record that we sent this roundup type to the user, so the next run inside
     * MIN_INTERVAL_DAYS skips them.
     */
    protected function markRoundupSent(int $userId, string $mode, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        UserDigest::updateOrCreate(
            ['userid' => $userId, 'mode' => $mode],
            ['lastsent' => now()]
        );
    }
}
