<?php

namespace Tests\Feature\Membership;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Services\Ripple\RippleEngagementService;
use App\Services\TnMembershipBackfillService as Backfill;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillTnCommandTest extends TestCase
{
    /**
     * A post's own row on $group. TN API ingestion is the only writer of
     * mod_messaging_allowed = 0; a TN post that came in by email has a tnpostid and the
     * column's default of 1.
     */
    private function tnPost(User $user, Group $group, bool $addressed, Carbon $arrival, array $row = []): int
    {
        $message = $this->createTestMessage($user, $group, [
            'tnpostid' => 'tn'.uniqid(),
            'arrival' => $arrival,
        ]);
        DB::table('messages_groups')->where('msgid', $message->id)
            ->update(array_merge(['mod_messaging_allowed' => $addressed ? 1 : 0], $row));

        return $message->id;
    }

    /** A TN poster as ingestion resolves them, through users.tnuserid. */
    private function tnUser(): User
    {
        return $this->createTestUser(['tnuserid' => random_int(900000000, 999999999)]);
    }

    /** @return array{0: User, 1: Group} */
    private function unaddressedPoster(?Carbon $arrival = null): array
    {
        $user = $this->tnUser();
        $group = $this->createTestGroup();
        $this->tnPost($user, $group, false, $arrival ?? now()->subMonth());

        return [$user, $group];
    }

    private function backfill(bool $send = true): array
    {
        return (new Backfill())->backfill($send);
    }

    /** This test's pair from a run, which also sees every other unaddressed row in the database. */
    private function pairFor(array $stats, User $user, Group $group): ?array
    {
        return collect($stats['pairs'])
            ->first(fn ($p) => $p['userid'] === $user->id && $p['groupid'] === $group->id);
    }

    private function membership(User $user, Group $group): ?object
    {
        return DB::table('memberships')->where('userid', $user->id)->where('groupid', $group->id)->first();
    }

    public function test_a_poster_with_no_membership_gets_an_implicit_one_dated_from_their_earliest_post(): void
    {
        $user = $this->tnUser();
        $group = $this->createTestGroup();
        $earliest = now()->subMonth()->startOfSecond();
        $later = $this->tnPost($user, $group, false, $earliest->copy()->addDay());
        $first = $this->tnPost($user, $group, false, $earliest);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CREATE, $pair['action']);
        $this->assertSame([$first, $later], $pair['msgids']);

        $m = $this->membership($user, $group);
        $this->assertSame(1, (int) $m->implicit);
        $this->assertSame(0, (int) $m->rippled);
        $this->assertSame(Membership::ADDED_ORIGIN_TN_UNADDRESSED, $m->added_origin);
        $this->assertSame(Membership::ROLE_MEMBER, $m->role);
        $this->assertSame(Membership::COLLECTION_APPROVED, $m->collection);
        $this->assertSame(Membership::POSTING_STATUS_DEFAULT, $m->ourPostingStatus);
        $this->assertSame([0, 0, 0], [(int) $m->emailfrequency, (int) $m->eventsallowed, (int) $m->volunteeringallowed]);
        $this->assertSame($earliest->toDateTimeString(), Carbon::parse($m->added)->toDateTimeString());

        $history = DB::table('memberships_history')->where('userid', $user->id)->where('groupid', $group->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(Membership::ADDED_ORIGIN_TN_UNADDRESSED, $history[0]->added_origin);
        $this->assertSame(1, (int) $history[0]->processingrequired);
        $this->assertSame($earliest->toDateTimeString(), Carbon::parse($history[0]->added)->toDateTimeString());

        $logs = DB::table('logs')->where('user', $user->id)->where('groupid', $group->id)
            ->where('type', 'Group')->where('subtype', 'Joined')->get();
        $this->assertCount(1, $logs);
        $this->assertSame(GroupPostIngestionService::JOIN_LOG_UNADDRESSED, $logs[0]->text);
        $this->assertNull($logs[0]->byuser);
        $this->assertSame($earliest->toDateTimeString(), Carbon::parse($logs[0]->timestamp)->toDateTimeString());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        [$user, $group] = $this->unaddressedPoster();

        $pair = $this->pairFor($this->backfill(send: false), $user, $group);

        $this->assertSame(Backfill::ACTION_CREATE, $pair['action']);
        $this->assertNull($this->membership($user, $group));
        $this->assertSame(0, DB::table('memberships_history')->where('userid', $user->id)->count());
        $this->assertSame(0, DB::table('logs')->where('user', $user->id)->count());
    }

    public function test_running_again_changes_nothing(): void
    {
        [$user, $group] = $this->unaddressedPoster();

        $this->backfill();
        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_ALREADY_IMPLICIT, $pair['action']);
        $this->assertSame(1, DB::table('memberships')->where('userid', $user->id)->count());
        $this->assertSame(1, DB::table('memberships_history')->where('userid', $user->id)->count());
        $this->assertSame(1, DB::table('logs')->where('user', $user->id)->count());
    }

    public function test_an_addressed_post_on_the_same_group_means_they_consented(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        // A TN post by email keeps the column default, and the email path only takes a post to
        // a group the poster has joined.
        $this->tnPost($user, $group, true, now()->subYear());

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CONSENTED, $pair['action']);
        $this->assertNull($this->membership($user, $group));
    }

    public function test_an_addressed_rippled_in_copy_is_not_consent(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        // Rippling inserts its copies with the column default of 1. The poster chose nothing.
        $this->tnPost($user, $group, true, now()->subWeek(), ['rippled_in' => 1]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CREATE, $pair['action']);
    }

    public function test_an_unaddressed_rippled_in_copy_is_not_read(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->tnPost($user, $group, false, now()->subWeek(), ['rippled_in' => 1]);

        $this->assertNull($this->pairFor($this->backfill(), $user, $group));
        $this->assertNull($this->membership($user, $group));
    }

    public function test_an_ordinary_membership_is_left_alone(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->createMembership($user, $group, ['added_origin' => Membership::ADDED_ORIGIN_MANUAL]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
        $m = $this->membership($user, $group);
        $this->assertSame(0, (int) $m->implicit);
        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $m->added_origin);
    }

    public function test_a_ripple_membership_is_left_alone(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->createMembership($user, $group, [
            'rippled' => 1, 'implicit' => 1, 'added_origin' => Membership::ADDED_ORIGIN_RIPPLED,
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->membership($user, $group)->added_origin);
    }

    public function test_a_tn_unaddressed_membership_without_the_flag_is_made_implicit(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->createMembership($user, $group, [
            'implicit' => 0, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_SET_IMPLICIT, $pair['action']);
        $this->assertSame(1, (int) $this->membership($user, $group)->implicit);
    }

    public function test_a_tn_unaddressed_membership_that_was_cleared_stays_ordinary(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->createMembership($user, $group, [
            'implicit' => 0, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);
        // TN's partner add clears it without a post: consent only goes one way.
        DB::table('logs')->insert([
            'timestamp' => now()->subWeek(), 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $user->id, 'byuser' => $user->id, 'groupid' => $group->id,
            'text' => RippleEngagementService::LOG_PREFIX.'joined through TrashNothing',
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CLEARED, $pair['action']);
        $this->assertSame(0, (int) $this->membership($user, $group)->implicit);
    }

    public function test_a_clear_from_before_the_membership_does_not_count(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        DB::table('logs')->insert([
            'timestamp' => now()->subYear(), 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $user->id, 'byuser' => $user->id, 'groupid' => $group->id,
            'text' => RippleEngagementService::LOG_PREFIX.'posted on this community',
        ]);
        $this->createMembership($user, $group, [
            'implicit' => 0, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_SET_IMPLICIT, $pair['action']);
    }

    public function test_a_banned_poster_gets_no_membership(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_BANNED, $pair['action']);
        $this->assertNull($this->membership($user, $group));
    }

    public function test_a_deleted_poster_gets_no_membership(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_NO_USER, $pair['action']);
        $this->assertNull($this->membership($user, $group));
    }

    public function test_a_poster_with_no_tnuserid_gets_no_membership(): void
    {
        // Before 1c7ac72deb the API path gave a post to whichever account had TN's id, or to a
        // stub made with it; neither is linked to TN, and neither is the poster.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->tnPost($user, $group, false, now()->subMonth());

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_NOT_LINKED, $pair['action']);
        $this->assertNull($this->membership($user, $group));
    }

    public function test_reports_which_pairs_lose_contact_once_it_is_per_group(): void
    {
        // Mixed: addressed a post to another group, so contactable everywhere today.
        [$mixed, $group] = $this->unaddressedPoster();
        $this->tnPost($mixed, $this->createTestGroup(), true, now()->subYear());
        // Mixed, but an ordinary member of this group, so its moderators keep contact.
        [$member, $memberGroup] = $this->unaddressedPoster();
        $this->tnPost($member, $this->createTestGroup(), true, now()->subYear());
        $this->createMembership($member, $memberGroup);
        // Never addressed anything: not contactable today either.
        [$only, $onlyGroup] = $this->unaddressedPoster();

        $stats = $this->backfill(send: false);

        $pair = $this->pairFor($stats, $mixed, $group);
        $this->assertTrue($pair['mixed']);
        $this->assertTrue($pair['losing_contact']);

        $pair = $this->pairFor($stats, $member, $memberGroup);
        $this->assertTrue($pair['mixed']);
        $this->assertFalse($pair['losing_contact']);

        $pair = $this->pairFor($stats, $only, $onlyGroup);
        $this->assertFalse($pair['mixed']);
        $this->assertFalse($pair['losing_contact']);

        $this->assertGreaterThanOrEqual(2, $stats['mixed_posters']);
        $this->assertGreaterThanOrEqual(1, $stats['pairs_losing_contact']);
    }

    public function test_command_is_a_dry_run_unless_sent(): void
    {
        [$user, $group] = $this->unaddressedPoster();

        $this->artisan('memberships:backfill-tn')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
        $this->assertNull($this->membership($user, $group));

        $this->artisan('memberships:backfill-tn', ['--send' => true])
            ->expectsOutputToContain('Done, for')
            ->assertSuccessful();
        $this->assertSame(1, (int) $this->membership($user, $group)->implicit);
    }
}
