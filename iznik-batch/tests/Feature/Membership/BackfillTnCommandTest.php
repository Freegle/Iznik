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

    /**
     * Unaddressed, then addressed later, with no membership: ingestion would have placed them
     * and then cleared the placement. The first run does both, so a second run finds an
     * ordinary membership and changes nothing.
     */
    public function test_a_later_addressed_post_with_no_membership_gives_an_ordinary_one_on_the_first_run(): void
    {
        $arrival = now()->subMonth()->startOfSecond();
        [$user, $group] = $this->unaddressedPoster($arrival);
        $this->tnPost($user, $group, true, now()->subWeek());

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CREATE_AND_CLEAR, $pair['action']);
        $m = $this->membership($user, $group);
        $this->assertSame(0, (int) $m->implicit);
        $this->assertSame(Membership::ADDED_ORIGIN_TN_UNADDRESSED, $m->added_origin);
        $this->assertSame($arrival->toDateTimeString(), Carbon::parse($m->added)->toDateTimeString());
        $this->assertTrue($pair['contact_after']);
        $this->assertSame(1, DB::table('logs')->where('user', $user->id)->where('groupid', $group->id)
            ->where('text', RippleEngagementService::LOG_PREFIX.GroupPostIngestionService::REASON_CONSENTED)->count());

        $logs = DB::table('logs')->where('user', $user->id)->count();
        $pair = $this->pairFor($this->backfill(), $user, $group);
        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
        $this->assertSame(0, (int) $this->membership($user, $group)->implicit);
        $this->assertSame($logs, DB::table('logs')->where('user', $user->id)->count());
    }

    public function test_a_poster_who_consented_once_and_has_no_membership_now_gets_an_implicit_one(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        // A TN post by email keeps the column default, and the email path only takes a post to
        // a group the poster has joined. With no membership now, they have left since, and
        // leaving and being placed back makes a new implicit row.
        $this->tnPost($user, $group, true, now()->subYear());

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CREATE, $pair['action']);
        $this->assertSame(1, (int) $this->membership($user, $group)->implicit);
    }

    public function test_a_poster_who_consented_and_is_still_a_member_is_left_alone(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $group, true, now()->subYear());
        $this->createMembership($user, $group, ['added_origin' => Membership::ADDED_ORIGIN_PARTNER]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
        $this->assertSame(0, (int) $this->membership($user, $group)->implicit);
    }

    public function test_an_addressed_post_since_it_was_added_keeps_a_tn_unaddressed_membership_ordinary(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $group, true, now()->subWeek());
        $this->createMembership($user, $group, [
            'implicit' => 0, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
        $this->assertSame(0, (int) $this->membership($user, $group)->implicit);
        $this->assertTrue($pair['contact_after']);
    }

    public function test_an_addressed_post_from_before_the_membership_is_not_consent_to_it(): void
    {
        // They consented, left, and were placed back: the consent went with the old row.
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $group, true, now()->subYear());
        $this->createMembership($user, $group, [
            'implicit' => 1, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_ALREADY_IMPLICIT, $pair['action']);
        $this->assertSame(1, (int) $this->membership($user, $group)->implicit);
    }

    public function test_an_addressed_post_since_it_was_added_clears_an_implicit_membership(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $group, true, now()->subWeek());
        $this->createMembership($user, $group, [
            'implicit' => 1, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CLEAR, $pair['action']);
        $this->assertSame(0, (int) $this->membership($user, $group)->implicit);
        $this->assertTrue($pair['contact_after']);
        $this->assertSame(1, DB::table('logs')->where('user', $user->id)->where('groupid', $group->id)
            ->where('text', RippleEngagementService::LOG_PREFIX.GroupPostIngestionService::REASON_CONSENTED)->count());

        // Cleared once: a second run finds it ordinary and leaves it.
        $pair = $this->pairFor($this->backfill(), $user, $group);
        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
    }

    public function test_an_addressed_post_to_another_group_does_not_clear_an_implicit_membership(): void
    {
        // Consent is per group. The mixed-poster rule (one addressed post anywhere opens every
        // group) is today's contact rule and is going; it must not decide anything here.
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $this->createTestGroup(), true, now()->subWeek());
        $this->createMembership($user, $group, [
            'implicit' => 1, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_ALREADY_IMPLICIT, $pair['action']);
        $this->assertSame(1, (int) $this->membership($user, $group)->implicit);
        $this->assertTrue($pair['contact_today']);
        $this->assertFalse($pair['contact_after']);
    }

    public function test_an_addressed_post_since_it_was_added_clears_a_ripple_membership(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $group, true, now()->subWeek());
        // Before 2a's last sync a ripple join can have rippled = 1 and implicit = 0.
        $this->createMembership($user, $group, [
            'rippled' => 1, 'implicit' => 0, 'added_origin' => Membership::ADDED_ORIGIN_RIPPLED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_CLEAR, $pair['action']);
        $m = $this->membership($user, $group);
        $this->assertSame([0, 0], [(int) $m->rippled, (int) $m->implicit]);
        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $m->added_origin);
    }

    public function test_an_addressed_rippled_in_copy_does_not_count_as_contact_today(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        // Rippling inserts its copies with the column default of 1. The poster chose nothing,
        // and modmessaging.UsersUnaddressedOnly reads only a post's own row.
        $this->tnPost($user, $this->createTestGroup(), true, now()->subWeek(), ['rippled_in' => 1]);

        $pair = $this->pairFor($this->backfill(send: false), $user, $group);

        $this->assertFalse($pair['contact_today']);
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

    public function test_a_tn_unaddressed_membership_without_the_flag_is_never_made_implicit(): void
    {
        // Cleared with no post (TN's partner add), or merged with a membership the member chose
        // and left with no "became ordinary" log. Either way it is ordinary, and stays so.
        [$user, $group] = $this->unaddressedPoster();
        $this->createMembership($user, $group, [
            'implicit' => 0, 'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED, 'added' => now()->subMonth(),
        ]);

        $pair = $this->pairFor($this->backfill(), $user, $group);

        $this->assertSame(Backfill::ACTION_EXISTING, $pair['action']);
        $this->assertSame(0, (int) $this->membership($user, $group)->implicit);
        $this->assertTrue($pair['contact_after']);
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

    public function test_reports_contact_today_and_per_group(): void
    {
        // An addressed post to another group: today's mixed-poster rule opens this group too.
        // Per group, an implicit membership here is not contactable.
        [$mixed, $group] = $this->unaddressedPoster();
        $this->tnPost($mixed, $this->createTestGroup(), true, now()->subYear());
        // Mixed, and an ordinary member of this group, so its moderators keep contact.
        [$member, $memberGroup] = $this->unaddressedPoster();
        $this->tnPost($member, $this->createTestGroup(), true, now()->subYear());
        $this->createMembership($member, $memberGroup);
        // Never addressed anything, so restricted everywhere today, but an ordinary member of
        // this group: per group, its moderators gain contact.
        [$restricted, $restrictedGroup] = $this->unaddressedPoster();
        $this->createMembership($restricted, $restrictedGroup);
        // Never addressed anything and no membership: no contact either way.
        [$only, $onlyGroup] = $this->unaddressedPoster();

        $stats = $this->backfill(send: false);

        $contact = fn (User $u, Group $g) => array_intersect_key($this->pairFor($stats, $u, $g),
            ['contact_today' => 1, 'contact_after' => 1]);
        $this->assertSame(['contact_today' => true, 'contact_after' => false], $contact($mixed, $group));
        $this->assertSame(['contact_today' => true, 'contact_after' => true], $contact($member, $memberGroup));
        $this->assertSame(['contact_today' => false, 'contact_after' => true], $contact($restricted, $restrictedGroup));
        $this->assertSame(['contact_today' => false, 'contact_after' => false], $contact($only, $onlyGroup));

        $this->assertGreaterThanOrEqual(2, $stats['mixed_posters']);
        $this->assertGreaterThanOrEqual(1, $stats['pairs_losing_contact']);
        $this->assertGreaterThanOrEqual(1, $stats['pairs_gaining_contact']);
    }

    public function test_a_ripple_membership_is_not_contactable_per_group(): void
    {
        [$user, $group] = $this->unaddressedPoster();
        $this->tnPost($user, $this->createTestGroup(), true, now()->subYear());
        // Before 2a's last sync a ripple join can have rippled = 1 and implicit = 0.
        $this->createMembership($user, $group, ['rippled' => 1, 'implicit' => 0]);

        $pair = $this->pairFor($this->backfill(send: false), $user, $group);

        $this->assertTrue($pair['contact_today']);
        $this->assertFalse($pair['contact_after']);
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
