<?php

namespace Tests\Feature\Mail;

use App\Mail\Newsfeed\NewsfeedModNotifMail;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NewsfeedModNotifCommandTest extends TestCase
{
    private function createNewsfeedPost(int $userId, int $groupId, array $attributes = []): int
    {
        return DB::table('newsfeed')->insertGetId(array_merge([
            'userid'    => $userId,
            'groupid'   => $groupId,
            'type'      => 'Message',
            'message'   => 'A chitchat post message.',
            'added'     => now()->subHours(1),
            'timestamp' => now()->subHours(1),
            'position'  => DB::raw("ST_GeomFromText('POINT(-0.1278 51.5074)', 3857)"),
        ], $attributes));
    }

    public function test_smoke_no_mods(): void
    {
        Mail::fake();

        $this->artisan('mail:newsfeed-mod-notif')
            ->expectsOutputToContain('Notified 0 mod(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_notifies_mod_with_new_posts(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->createNewsfeedPost($member->id, $group->id);

        $this->artisan('mail:newsfeed-mod-notif')
            ->expectsOutputToContain('Notified 1 mod(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_mod_with_no_unseen_posts(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $postId = $this->createNewsfeedPost($member->id, $group->id);

        // Mark the post as seen
        DB::table('newsfeed_users')->insert([
            'userid' => $mod->id,
            'newsfeedid' => $postId,
        ]);

        $this->artisan('mail:newsfeed-mod-notif')
            ->expectsOutputToContain('Notified 0 mod(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_deleted_posts(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->createNewsfeedPost($member->id, $group->id, ['deleted' => now()]);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_hidden_posts(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->createNewsfeedPost($member->id, $group->id, ['hidden' => now()]);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_post_replies(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $parentId = $this->createNewsfeedPost($member->id, $group->id);
        // A reply — has replyto set
        $this->createNewsfeedPost($member->id, $group->id, ['replyto' => $parentId]);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        // Only the parent is visible; reply is excluded
        Mail::assertSentCount(1);
    }

    public function test_skips_posts_by_the_mod_themselves(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Mod posted to their own newsfeed — should be excluded from notification
        $this->createNewsfeedPost($mod->id, $group->id);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_posts_older_than_24_hours(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        // Post from 25 hours ago
        $this->createNewsfeedPost($member->id, $group->id, [
            'added' => now()->subHours(25),
            'timestamp' => now()->subHours(25),
        ]);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_mod_with_notif_setting_disabled(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        DB::table('users')->where('id', $mod->id)
            ->update(['settings' => json_encode(['modnotifnewsfeed' => false])]);

        $this->createNewsfeedPost($member->id, $group->id);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_updates_lastseen_after_notifying(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $postId = $this->createNewsfeedPost($member->id, $group->id);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        $seen = DB::table('newsfeed_users')->where('userid', $mod->id)->value('newsfeedid');
        $this->assertEquals($postId, $seen);
    }

    public function test_deduplicates_mods_across_groups(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Same mod on both groups
        $this->createMembership($mod, $group1, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($mod, $group2, ['role' => Membership::ROLE_OWNER]);

        $this->createNewsfeedPost($member->id, $group1->id);

        $this->artisan('mail:newsfeed-mod-notif')
            ->expectsOutputToContain('Notified 1 mod(s)')
            ->assertExitCode(0);

        // Should only send one email even though mod is on two groups
        Mail::assertSentCount(1);
    }

    public function test_dry_run_does_not_send_or_update_lastseen(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $this->createNewsfeedPost($member->id, $group->id);

        $this->artisan('mail:newsfeed-mod-notif', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would notify 1 mod(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        $this->assertNull(
            DB::table('newsfeed_users')->where('userid', $mod->id)->value('newsfeedid')
        );
    }

    public function test_skips_non_message_story_aboutme_types(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Types not in (Message, Story, AboutMe)
        $this->createNewsfeedPost($member->id, $group->id, ['type' => 'CommunityEvent']);
        $this->createNewsfeedPost($member->id, $group->id, ['type' => 'Alert']);

        $this->artisan('mail:newsfeed-mod-notif')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
