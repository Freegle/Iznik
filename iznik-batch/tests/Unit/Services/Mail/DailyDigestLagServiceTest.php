<?php

namespace Tests\Unit\Services\Mail;

use App\Models\Membership;
use App\Models\User;
use App\Services\Mail\DailyDigestLagService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyDigestLagServiceTest extends TestCase
{
    private DailyDigestLagService $service;

    /** 13:00 London, when the check runs. */
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DailyDigestLagService;
        $this->now = Carbon::parse('2026-10-30 13:00:00', 'Europe/London');
    }

    /**
     * A member active this week with an Approved daily membership whose last daily digest went
     * out $daysAgo days ago. $user and $membership override the defaults.
     */
    private function member(int $daysAgo, array $user = [], array $membership = []): User
    {
        $u = $this->createTestUser(array_merge(['lastaccess' => $this->now->copy()->subDays(2)], $user));
        $this->createMembership($u, $this->createTestGroup(), array_merge(
            ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY],
            $membership
        ));
        DB::table('users_digests')->insert([
            'userid' => $u->id,
            'mode' => 'daily',
            'lastsent' => $this->now->copy()->subDays($daysAgo)->setTimezone('UTC')->toDateTimeString(),
        ]);

        return $u;
    }

    /** The lagging member ids among $users, so rows other tests left behind cannot count. */
    private function lagging(array $users): array
    {
        return $this->service->laggingQuery($this->now)
            ->whereIn('users.id', array_map(fn (User $u) => $u->id, $users))
            ->pluck('users.id')
            ->sort()->values()->all();
    }

    public function test_counts_a_member_the_digest_would_mail(): void
    {
        $owed = $this->member(1);

        $this->assertSame([$owed->id], $this->lagging([$owed]));
    }

    public function test_sent_today_is_not_lagging(): void
    {
        $sent = $this->member(0);

        $this->assertSame([], $this->lagging([$sent]));
    }

    public function test_leaves_out_members_the_digest_never_mails(): void
    {
        $tn = $this->member(40, ['tnuserid' => 900000000 + random_int(1, 99999999)]);
        $bouncing = $this->member(40, ['bouncing' => 1]);
        $noDaily = $this->member(40, [], ['emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE]);
        $pending = $this->member(40, [], ['collection' => Membership::COLLECTION_PENDING]);
        $dormant = $this->member(40, ['lastaccess' => $this->now->copy()->subDays(60)]);
        $owed = $this->member(40);

        $this->assertSame([$owed->id], $this->lagging([$tn, $bouncing, $noDaily, $pending, $dormant, $owed]));
    }

    public function test_leaves_out_a_member_who_turned_mail_off(): void
    {
        $off = $this->member(40, ['settings' => ['simplemail' => User::SIMPLE_MAIL_NONE]]);
        $full = $this->member(40, ['settings' => ['simplemail' => User::SIMPLE_MAIL_FULL]]);

        $this->assertSame([$full->id], $this->lagging([$off, $full]));
    }

    public function test_counts_any_periodic_cadence_as_daily(): void
    {
        // A daily run folds hourly to daily into one roll-up, so a 4-hourly member is owed one.
        $fourHourly = $this->member(3, [], ['emailfrequency' => 4]);

        $this->assertSame([$fourHourly->id], $this->lagging([$fourHourly]));
    }

    public function test_one_daily_membership_among_others_is_enough(): void
    {
        $owed = $this->member(3, [], ['emailfrequency' => Membership::EMAIL_FREQUENCY_NEVER]);
        $this->createMembership($owed, $this->createTestGroup(), ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);

        $this->assertSame([$owed->id], $this->lagging([$owed]));
    }

    public function test_measure_splits_out_the_part_more_than_a_week_behind(): void
    {
        // measure() counts the whole table, so compare before and after adding members.
        $before = $this->service->measure($this->now);

        $this->member(2);
        $this->member(10);
        $this->member(40, ['tnuserid' => 900000000 + random_int(1, 99999999)]);

        $after = $this->service->measure($this->now);

        $this->assertSame(2, $after['lagging'] - $before['lagging']);
        $this->assertSame(1, $after['over_a_week'] - $before['over_a_week']);
    }
}
