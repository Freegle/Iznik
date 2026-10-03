<?php

namespace App\Services\Concerns;

use App\Models\User;
use App\Models\UserDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Shared plumbing for the user-centric "roundup" digests (community events and
 * volunteering opportunities).
 *
 * The site is national now: there is no group to be eligible for and no group
 * to attribute an item to, so eligibility is just the user's own opt-in flag
 * (users.eventsallowed / users.volunteeringallowed) and email frequency
 * (users.emailfrequency), plus the per-user cadence guard against sending the
 * same roundup twice inside MIN_INTERVAL_DAYS. Both roundups send ONE email
 * per user covering every eligible item, deduplicated by item id.
 */
trait BuildsUserRoundups
{
    /** Minimum days between roundups of a given kind for one user (V1: DATEDIFF >= 3). */
    public const MIN_INTERVAL_DAYS = 3;

    /**
     * Stream users eligible for a roundup: deliverable (active / not on holiday /
     * not bouncing / simplemail != None), opted in via the given flag, a
     * non-zero email frequency, and not already sent this roundup type within
     * MIN_INTERVAL_DAYS.
     *
     * @param  string  $allowedColumn  'eventsallowed' | 'volunteeringallowed'.
     * @param  string  $mode  users_digests.mode ('events' | 'volunteering').
     */
    protected function eligibleUsers(string $allowedColumn, string $mode): LazyCollection
    {
        return User::query()
            ->select(['users.id'])
            ->where("users.$allowedColumn", 1)
            ->where('users.emailfrequency', '!=', 0)
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
