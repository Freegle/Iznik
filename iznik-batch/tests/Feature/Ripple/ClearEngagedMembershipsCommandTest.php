<?php

namespace Tests\Feature\Ripple;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Discourse 10239: a moderator could not write to a member whose only tie to their
 * community was a post that rippled in, even after that member had dealt with one of the
 * community's own members. ripple:clear-engaged makes such a membership ordinary once the
 * member has done something with the community:
 *
 *  - posted on it;
 *  - replied to a post that was posted on it;
 *  - written back, in a chat about their rippled post, to one of its ordinary members.
 */
class ClearEngagedMembershipsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts from no saved position, as a first run does.
        DB::table('config')->whereIn('key', [
            'ripple_engaged_last_chat_message',
            'ripple_engaged_last_post_arrival',
        ])->delete();
    }

    private function rippleMembership(User $user, Group $group): void
    {
        $this->createMembership($user, $group, ['added' => now()->subDays(5)]);
        DB::table('memberships')->where('userid', $user->id)->where('groupid', $group->id)
            ->update(['rippled' => 1]);
    }

    private function isRippled(User $user, Group $group): bool
    {
        return (bool) DB::table('memberships')
            ->where('userid', $user->id)->where('groupid', $group->id)->value('rippled');
    }

    /** A post of $poster's that started on $home and rippled into $into. */
    private function rippledPost(User $poster, Group $home, Group $into): int
    {
        $msg = $this->createTestMessage($poster, $home, ['arrival' => now()->subDays(3)]);
        DB::table('messages_groups')->insert([
            'msgid' => $msg->id,
            'groupid' => $into->id,
            'collection' => 'Approved',
            'arrival' => now()->subDays(2),
            'rippled_in' => 1,
        ]);

        return $msg->id;
    }

    private function chat(User $a, User $b): int
    {
        return (int) DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $a->id,
            'user2' => $b->id,
        ]);
    }

    private function say(int $chatid, User $from, ?int $refmsgid = null, array $extra = []): int
    {
        return (int) DB::table('chat_messages')->insertGetId(array_merge([
            'chatid' => $chatid,
            'userid' => $from->id,
            'type' => $refmsgid ? 'Interested' : 'Default',
            'refmsgid' => $refmsgid,
            'message' => 'Hello',
            'date' => now()->subHour(),
        ], $extra));
    }

    private function run_(): void
    {
        $this->artisan('ripple:clear-engaged')->assertExitCode(0);
    }

    // ------------------------------------------------------------- posting on the community

    public function test_posting_on_the_community_makes_the_membership_ordinary(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);

        $this->createTestMessage($member, $group, ['arrival' => now()->subHour()]);

        $this->run_();

        $this->assertFalse($this->isRippled($member, $group));
        $this->assertSame(
            'Ripple-created membership became ordinary: posted on this community',
            DB::table('logs')->where('user', $member->id)->where('groupid', $group->id)
                ->where('type', 'Group')->where('subtype', 'Joined')->value('text')
        );
    }

    public function test_a_trashnothing_post_placed_on_a_community_the_member_never_chose_does_not_count(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);

        $msg = $this->createTestMessage($member, $group, ['arrival' => now()->subHour()]);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['mod_messaging_allowed' => 0]);

        $this->run_();

        $this->assertTrue($this->isRippled($member, $group));
    }

    public function test_a_post_still_pending_does_not_count(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);

        $msg = $this->createTestMessage($member, $group, ['arrival' => now()->subHour()]);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['collection' => 'Pending']);

        $this->run_();

        $this->assertTrue($this->isRippled($member, $group));
    }

    // ----------------------------------------------------- replying to the community's posts

    public function test_replying_to_a_post_posted_on_the_community_makes_the_membership_ordinary(): void
    {
        $member = $this->createTestUser();
        $local = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);
        $this->createMembership($local, $group);

        $post = $this->createTestMessage($local, $group);
        $this->say($this->chat($member, $local), $member, $post->id);

        $this->run_();

        $this->assertFalse($this->isRippled($member, $group));
    }

    public function test_replying_to_a_post_that_only_rippled_into_the_community_does_not_count(): void
    {
        // Both people are outsiders to this community: the post started elsewhere.
        $member = $this->createTestUser();
        $poster = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $this->say($this->chat($member, $poster), $member, $post);

        $this->run_();

        $this->assertTrue($this->isRippled($member, $group));
    }

    // ------------------------------------------- writing back about the member's rippled post

    public function test_writing_back_to_a_member_of_the_community_about_a_rippled_post_makes_it_ordinary(): void
    {
        $poster = $this->createTestUser();
        $local = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->createMembership($local, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $chat = $this->chat($local, $poster);
        $this->say($chat, $local, $post);
        $this->say($chat, $poster);

        $this->run_();

        $this->assertFalse($this->isRippled($poster, $group));
        $this->assertSame(
            'Ripple-created membership became ordinary: wrote back to a member about their post',
            DB::table('logs')->where('user', $poster->id)->where('groupid', $group->id)
                ->where('type', 'Group')->where('subtype', 'Joined')->value('text')
        );
    }

    public function test_a_reply_the_poster_never_answered_does_not_count(): void
    {
        $poster = $this->createTestUser();
        $local = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->createMembership($local, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $this->say($this->chat($local, $poster), $local, $post);

        $this->run_();

        $this->assertTrue($this->isRippled($poster, $group));
    }

    public function test_writing_back_to_someone_who_is_not_an_ordinary_member_there_does_not_count(): void
    {
        // The replier's own membership of the community is one rippling made, so they
        // are no more the community's member than the poster is.
        $poster = $this->createTestUser();
        $other = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->rippleMembership($other, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $chat = $this->chat($other, $poster);
        $this->say($chat, $other, $post);
        $this->say($chat, $poster);

        $this->run_();

        $this->assertTrue($this->isRippled($poster, $group));
    }

    public function test_writing_back_to_a_trashnothing_placement_there_does_not_count(): void
    {
        // TrashNothing put the replier on the community without them choosing it, so they
        // are no more its member than the poster is.
        $poster = $this->createTestUser();
        $other = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->createMembership($other, $group, [
            'implicit' => 1,
            'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED,
        ]);

        $post = $this->rippledPost($poster, $home, $group);
        $chat = $this->chat($other, $poster);
        $this->say($chat, $other, $post);
        $this->say($chat, $poster);

        $this->run_();

        $this->assertTrue($this->isRippled($poster, $group));
    }

    public function test_a_rejected_chat_message_does_not_count(): void
    {
        $poster = $this->createTestUser();
        $local = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->createMembership($local, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $chat = $this->chat($local, $poster);
        $this->say($chat, $local, $post);
        $this->say($chat, $poster, null, ['reviewrejected' => 1]);

        $this->run_();

        $this->assertTrue($this->isRippled($poster, $group));
    }

    // ------------------------------------------------------------------------ scope and runs

    public function test_only_the_community_concerned_is_cleared(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $elsewhere = $this->createTestGroup();
        $this->rippleMembership($member, $group);
        $this->rippleMembership($member, $elsewhere);

        $this->createTestMessage($member, $group, ['arrival' => now()->subHour()]);

        $this->run_();

        $this->assertFalse($this->isRippled($member, $group));
        $this->assertTrue($this->isRippled($member, $elsewhere));
    }

    public function test_an_ordinary_membership_is_left_alone_and_logs_nothing(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($member, $group);

        $this->createTestMessage($member, $group, ['arrival' => now()->subHour()]);

        $this->run_();

        $this->assertSame(0, DB::table('logs')->where('user', $member->id)
            ->where('text', 'like', 'Ripple-created membership became ordinary%')->count());
    }

    public function test_a_later_run_picks_up_only_new_activity(): void
    {
        $member = $this->createTestUser();
        $local = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);
        $this->createMembership($local, $group);

        $this->run_();
        $this->assertTrue($this->isRippled($member, $group));

        $post = $this->createTestMessage($local, $group);
        $this->say($this->chat($member, $local), $member, $post->id, ['date' => now()]);

        $this->run_();

        $this->assertFalse($this->isRippled($member, $group));
    }

    public function test_a_chat_message_still_under_review_is_counted_once_it_is_approved(): void
    {
        $poster = $this->createTestUser();
        $local = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->createMembership($local, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $chat = $this->chat($local, $poster);
        $this->say($chat, $local, $post);
        $reply = $this->say($chat, $poster, null, ['reviewrequired' => 1]);

        $this->run_();
        $this->assertTrue($this->isRippled($poster, $group));

        DB::table('chat_messages')->where('id', $reply)->update(['reviewrequired' => 0]);
        $this->run_();

        $this->assertFalse($this->isRippled($poster, $group));
    }

    // ------------------------------------------------------------------------- backfill

    private function savedPositions(): array
    {
        return DB::table('config')->whereIn('key', [
            'ripple_engaged_last_chat_message',
            'ripple_engaged_last_post_arrival',
        ])->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_a_backfill_clears_engagement_older_than_the_scheduled_run_looks(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);
        DB::table('memberships')->where('userid', $member->id)->update(['added' => now()->subDays(90)]);
        $this->createTestMessage($member, $group, ['arrival' => now()->subDays(60)]);

        $this->run_();
        $this->assertTrue($this->isRippled($member, $group), 'the scheduled run only looks back 30 days');

        $this->artisan('ripple:clear-engaged', ['--since' => now()->subDays(90)->toDateString()])
            ->assertExitCode(0);

        $this->assertFalse($this->isRippled($member, $group));
    }

    public function test_a_backfill_catches_an_old_write_back(): void
    {
        $poster = $this->createTestUser();
        $local = $this->createTestUser();
        $home = $this->createTestGroup();
        $group = $this->createTestGroup();
        $this->rippleMembership($poster, $group);
        $this->createMembership($local, $group);

        $post = $this->rippledPost($poster, $home, $group);
        $chat = $this->chat($local, $poster);
        $this->say($chat, $local, $post, ['date' => now()->subDays(61)]);
        $this->say($chat, $poster, null, ['date' => now()->subDays(60)]);

        $this->artisan('ripple:clear-engaged', ['--since' => now()->subDays(90)->toDateString()])
            ->assertExitCode(0);

        $this->assertFalse($this->isRippled($poster, $group));
    }

    public function test_a_backfill_leaves_the_scheduled_run_where_it_was(): void
    {
        $this->run_();
        $before = $this->savedPositions();

        $member = $this->createTestUser();
        $local = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->rippleMembership($member, $group);
        $this->createMembership($local, $group);
        $post = $this->createTestMessage($local, $group);
        $this->say($this->chat($member, $local), $member, $post->id);

        $this->artisan('ripple:clear-engaged', ['--since' => now()->subDays(90)->toDateString()])
            ->assertExitCode(0);

        $this->assertSame($before, $this->savedPositions());
    }

    public function test_a_backfill_refuses_a_date_it_cannot_read(): void
    {
        $this->artisan('ripple:clear-engaged', ['--since' => 'not a date'])
            ->assertExitCode(1);
    }
}
