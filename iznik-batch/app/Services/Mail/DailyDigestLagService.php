<?php

namespace App\Services\Mail;

use App\Models\Membership;
use App\Services\UnifiedDigestService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How many members the daily digest owes a digest it has not sent today. Read by the 13:00
 * mail:digest:daily-lag-check.
 *
 * A member counts only if the digest would mail them: the same gate as
 * UnifiedDigestService::getUsersForDigest (not deleted, no tnuserid, not bouncing), plus an
 * Approved membership set to a daily digest, which is the only kind a daily run builds from.
 * Without those the figure counted users_digests rows nobody would ever clear. On 2026-10-30
 * that was 6,891 "lagging" members, of whom 3,377 were TrashNothing accounts (mailed by
 * TrashNothing, never by us) and 2,745 had no daily membership left; 769 were owed a digest.
 * A row stays behind when someone moves every community off daily, so the stale part only grew.
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
            ->whereExists(function ($q) {
                $q->from('memberships')
                    ->whereColumn('memberships.userid', 'users.id')
                    ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                    ->where('memberships.emailfrequency', Membership::EMAIL_FREQUENCY_DAILY);
            });
    }

    private function londonDayStartUtc(?Carbon $now): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone('Europe/London')->startOfDay()->setTimezone('UTC');
    }
}
