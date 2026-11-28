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
     * Freegle groups eligible for a roundup of the given kind, keyed by id.
     *
     * Mirrors the per-group eligibility the old per-group services applied:
     * published, on-here Freegle groups that aren't playgrounds, aren't closed,
     * and have the relevant feature setting enabled (default on).
     *
     * Each value carries the friendly display name plus the /explore link for
     * the group, so the roundup can show a "Posted on <group>" byline matching
     * the message digest (UnifiedDigest "Posted by … on <group>").
     *
     * @param string $settingKey 'communityevents' | 'volunteering'
     * @return array<int,array{name:string,url:string}>
     */
    protected function eligibleGroups(string $settingKey): array
    {
        $userSite = config('freegle.sites.user');

        $candidates = Group::query()
            ->where('type', Group::TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->where('nameshort', 'NOT LIKE', '%playground%')
            ->get(['id', 'nameshort', 'namefull', 'settings']);

        $eligible = [];
        foreach ($candidates as $group) {
            if (!$group->getSetting($settingKey, true)) {
                continue;
            }
            if ($group->isClosed()) {
                continue;
            }
            $eligible[(int) $group->id] = [
                'name' => $group->namefull ?: $group->nameshort,
                'url'  => $userSite . '/explore/' . rawurlencode($group->nameshort),
            ];
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
            return User::query()->whereIn('id', [])->lazyById(500);
        }

        return User::query()
            ->select(['users.id'])
            ->whereExists(function ($q) use ($groupIds, $allowedColumn) {
                $q->from('memberships')
                    ->whereColumn('memberships.userid', 'users.id')
                    ->whereIn('memberships.groupid', $groupIds)
                    ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                    ->where("memberships.$allowedColumn", 1)
                    ->where('memberships.emailfrequency', '!=', 0);
            })
            ->whereNotExists(function ($q) use ($mode) {
                $q->from('users_digests')
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

    /**
     * Key that is equal for two postings of the same thing: the same member
     * posted them with the same title and the same remaining parts (place, and
     * start for an event). Case and spacing are ignored.
     *
     * An organiser who posts to each nearby community creates one row per
     * community, so matching on id alone shows a member of several of them the
     * same entry several times. The poster is part of the key so that the
     * mail's about() still names everyone whose content is in it.
     */
    protected function samePostingKey(?int $userId, ?string ...$parts): string
    {
        $norm = fn (?string $s): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s ?? '')));

        return implode("\x1f", [$userId ?? '', ...array_map($norm, $parts)]);
    }

    /**
     * Fold repeated postings into one entry each and attach the group details.
     *
     * Each item carries a 'samePostingKey' and is paired with the ids of the
     * user's groups it was posted on. Items sharing a key become one entry, at
     * the position of the first seen: the lowest id is the copy kept, so its
     * link is the same in every mail, and it lists the groups of all the copies.
     *
     * @param iterable<array{0: array, 1: array<int>}> $items [item data, group ids] pairs
     * @param array<int,array{name:string,url:string}> $eligibleGroups group id => display name + /explore link
     * @return array<int,array>
     */
    protected function foldSamePostings(iterable $items, array $eligibleGroups): array
    {
        $position = [];
        $groupIdsAt = [];
        $out = [];

        foreach ($items as [$data, $groupIds]) {
            $key = $data['samePostingKey'];
            unset($data['samePostingKey']);

            if (!isset($position[$key])) {
                $position[$key] = count($out);
                $groupIdsAt[$key] = [];
                $out[] = $data;
            } elseif ($data['id'] < $out[$position[$key]]['id']) {
                $out[$position[$key]] = $data;
            }

            $groupIdsAt[$key] = array_values(array_unique(array_merge($groupIdsAt[$key], $groupIds)));
        }

        foreach ($position as $key => $i) {
            $out[$i]['groups'] = array_values(array_filter(array_map(
                fn ($gid) => $eligibleGroups[$gid] ?? null,
                $groupIdsAt[$key]
            )));
        }

        return $out;
    }
}
