<?php

namespace App\Services\Mail;

use App\Models\Membership;
use App\Models\User;
use App\Services\UnifiedDigestService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How many members the daily digest owes a digest it has not sent today. Read by the 13:00
 * mail:digest:daily-lag-check.
 *
 * A member counts only if the digest would mail them: the same gate as
 * UnifiedDigestService::getUsersForDigest (not deleted, no tnuserid, not bouncing, mail not
 * turned off with simplemail None, and an Approved membership on any periodic cadence, which
 * a daily run folds into one roll-up). Without those the figure counted users_digests rows
 * nobody would ever clear. On 2026-10-30 that was 6,891 "lagging" members, of whom 3,377 were
 * TrashNothing accounts (mailed by TrashNothing, never by us), 2,745 had no daily membership
 * left, and 399 of those over a week behind had turned mail off. A row stays behind when
 * someone stops daily mail, so the stale part only grew.
 *
 * A member whose communities have had nothing new is not stamped (updateDigestTracker stamps
 * lastsent only when posts were examined), so they still count here while there is nothing to
 * send them. That part is small: 33 of the 40 left over a week behind on 2026-10-30.
 *
 * Dormancy is excluded by whether the member has used the site in the last 30 days, not by how
 * long ago we last mailed them, so a member who has fallen far behind still counts. Members
 * never sent a daily digest have no row and are not counted.
 */
class DailyDigestLagService
{
    public const ACTIVE_DAYS = 30;

    public const OVER_A_WEEK_DAYS = 7;

    /**
     * @return array{lagging: int, over_a_week: int}
     */
    public function measure(?Carbon $now = null): array
    {
        $dayStart = $this->londonDayStartUtc($now);

        return [
            'lagging' => $this->laggingQuery($now)->count(),
            // A rising figure here means the backlog is settling on the same people rather
            // than rotating.
            'over_a_week' => $this->laggingQuery($now)
                ->where('users_digests.lastsent', '<', $dayStart->copy()->subDays(self::OVER_A_WEEK_DAYS)->toDateTimeString())
                ->count(),
        ];
    }

    /**
     * Daily digest rows not sent since London midnight, for members the digest would mail.
     */
    public function laggingQuery(?Carbon $now = null): Builder
    {
        $dayStart = $this->londonDayStartUtc($now);

        return DB::table('users_digests')
            ->join('users', 'users.id', '=', 'users_digests.userid')
            ->where('users_digests.mode', UnifiedDigestService::MODE_DAILY)
            ->where('users_digests.lastsent', '<', $dayStart->toDateTimeString())
            ->whereNull('users.deleted')
            ->whereNull('users.tnuserid')
            ->where('users.bouncing', 0)
            ->where('users.lastaccess', '>=', $dayStart->copy()->subDays(self::ACTIVE_DAYS)->toDateTimeString())
            ->where(function ($q) {
                $q->whereJsonDoesntContainKey('users.settings->simplemail')
                    ->orWhere('users.settings->simplemail', '!=', User::SIMPLE_MAIL_NONE);
            })
            ->whereExists(function ($q) {
                // Any positive cadence: a daily run folds hourly to daily into one roll-up
                // (UnifiedDigestService::applyDigestFrequency).
                $q->from('memberships')
                    ->whereColumn('memberships.userid', 'users.id')
                    ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                    ->where('memberships.emailfrequency', '>', Membership::EMAIL_FREQUENCY_NEVER);
            });
    }

    private function londonDayStartUtc(?Carbon $now): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone('Europe/London')->startOfDay()->setTimezone('UTC');
    }
}
