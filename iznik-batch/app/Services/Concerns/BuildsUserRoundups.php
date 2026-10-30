<?php

namespace App\Services\Concerns;

use App\Models\User;
use App\Models\UserDigest;
use App\Support\GreatCircle;
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
                $q->from('users_digests')
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
    /**
     * Where to measure a member from: the place they chose in their settings, else their
     * last location. Null when we know neither.
     *
     * @return array{0: float, 1: float}|null
     */
    protected function memberLatLng(User $user): ?array
    {
        $chosen = User::chosenLatLng($user->settings, $user->tnuserid);
        if ($chosen) {
            return $chosen;
        }

        [$lat, $lng] = $user->getLatLng();

        return ($lat && $lng) ? [(float) $lat, (float) $lng] : null;
    }

    /**
     * The items whose reach covers a member, nearest first, then the online ones, which
     * reach everyone. Each item is shown around its own centre (lat, lng) as far as its
     * reachmiles, or the fallback radius when routing could not size it. An item with no
     * centre reaches nobody, and neither does anything but an online item reach a member
     * with no location. The Go lists apply the same rule (localreach.Within).
     *
     * @param  array<int,array>  $items  each with lat, lng, reachmiles and optionally online
     * @param  array{0: float, 1: float}|null  $latLng
     * @return array<int,array>
     */
    protected function itemsReaching(array $items, ?array $latLng): array
    {
        $fallback = (float) config('freegle.local_reach.fallback_miles');
        $local = [];
        $online = [];

        foreach ($items as $item) {
            if (! empty($item['online'])) {
                $online[] = $item;

                continue;
            }

            if ($latLng === null || $item['lat'] === null || $item['lng'] === null) {
                continue;
            }

            $miles = GreatCircle::distanceMiles($latLng[0], $latLng[1], $item['lat'], $item['lng']);
            if ($miles <= ($item['reachmiles'] ?? $fallback)) {
                $local[] = [$miles, $item];
            }
        }

        usort($local, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_merge(array_column($local, 1), $online);
    }

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
