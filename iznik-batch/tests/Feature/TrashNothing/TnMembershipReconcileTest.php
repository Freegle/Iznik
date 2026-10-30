<?php

namespace Tests\Feature\TrashNothing;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Ingestion\TnMembershipReconcileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TnMembershipReconcileTest extends TestCase
{
    /** A TN API post's own row on $group, with no membership behind it. */
    private function tnApiPost(User $user, Group $group, bool $chosen, Carbon $arrival): int
    {
        $message = $this->createTestMessage($user, $group, [
            'tnpostid' => 'tn'.uniqid(),
            'sourceheader' => GroupPostIngestionService::SOURCEHEADER,
            'arrival' => $arrival,
        ]);
        DB::table('messages_groups')->where('msgid', $message->id)
            ->update(['mod_messaging_allowed' => $chosen ? 1 : 0]);
        DB::table('memberships')->where('userid', $user->id)->where('groupid', $group->id)->delete();

        return $message->id;
    }

    private function poster(): array
    {
        return [$this->createTestUser(['tnuserid' => random_int(900000000, 999999999)]), $this->createTestGroup()];
    }

    private function membership(User $user, Group $group): ?object
    {
        return DB::table('memberships')->where('userid', $user->id)->where('groupid', $group->id)->first();
    }

    private function reconcile(bool $send = true): array
    {
        return app(TnMembershipReconcileService::class)->reconcile($send);
    }

    public function test_a_missing_membership_on_a_group_the_poster_did_not_choose_is_a_placement(): void
    {
        [$user, $group] = $this->poster();
        $arrival = now()->subHours(3)->startOfSecond();
        $this->tnApiPost($user, $group, false, $arrival);

        $this->reconcile();

        $m = $this->membership($user, $group);
        $this->assertNotNull($m);
        $this->assertSame(1, (int) $m->implicit);
        $this->assertSame(Membership::ADDED_ORIGIN_TN_UNADDRESSED, $m->added_origin);
        $this->assertSame($arrival->toDateTimeString(), Carbon::parse($m->added)->toDateTimeString());
        $this->assertSame(1, DB::table('logs')->where('user', $user->id)->where('groupid', $group->id)
            ->where('type', 'Group')->where('subtype', 'Joined')
            ->where('text', GroupPostIngestionService::JOIN_LOG_UNADDRESSED)->count());
    }

    public function test_a_missing_membership_on_a_group_the_poster_chose_is_ordinary(): void
    {
        [$user, $group] = $this->poster();
        $this->tnApiPost($user, $group, true, now()->subHours(3));

        $this->reconcile();

        $m = $this->membership($user, $group);
        $this->assertSame(0, (int) $m->implicit);
        $this->assertSame(Membership::ADDED_ORIGIN_PARTNER, $m->added_origin);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        [$user, $group] = $this->poster();
        $this->tnApiPost($user, $group, false, now()->subHours(3));

        $stats = $this->reconcile(send: false);

        $this->assertGreaterThanOrEqual(1, $stats['missing']);
        $this->assertNull($this->membership($user, $group));
    }

    public function test_a_poster_who_left_since_the_post_is_not_put_back(): void
    {
        [$user, $group] = $this->poster();
        $this->tnApiPost($user, $group, true, now()->subHours(3));
        DB::table('logs')->insert([
            'timestamp' => now()->subHour(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $user->id, 'byuser' => $user->id, 'groupid' => $group->id,
        ]);

        $this->reconcile();

        $this->assertNull($this->membership($user, $group));
    }

    public function test_a_banned_poster_is_not_put_back(): void
    {
        [$user, $group] = $this->poster();
        $this->tnApiPost($user, $group, false, now()->subHours(3));
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id, 'date' => now()]);

        $this->reconcile();

        $this->assertNull($this->membership($user, $group));
    }

    public function test_a_post_older_than_the_window_is_left_alone(): void
    {
        [$user, $group] = $this->poster();
        $this->tnApiPost($user, $group, false, now()->subDays(TnMembershipReconcileService::WINDOW_DAYS + 1));

        $this->reconcile();

        $this->assertNull($this->membership($user, $group));
    }

    public function test_a_post_that_is_not_from_the_tn_api_is_left_alone(): void
    {
        [$user, $group] = $this->poster();
        $msgid = $this->tnApiPost($user, $group, false, now()->subHours(3));
        DB::table('messages')->where('id', $msgid)->update(['sourceheader' => 'TN-email']);

        $this->reconcile();

        $this->assertNull($this->membership($user, $group));
    }

    public function test_the_command_runs(): void
    {
        [$user, $group] = $this->poster();
        $this->tnApiPost($user, $group, false, now()->subHours(3));

        $this->artisan('tn:reconcile-memberships')->assertSuccessful();

        $this->assertNotNull($this->membership($user, $group));
    }
}
