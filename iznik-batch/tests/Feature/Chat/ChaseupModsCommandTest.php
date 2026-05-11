<?php

namespace Tests\Feature\Chat;

use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChaseupModsCommandTest extends TestCase
{
    public function test_smoke_no_chats(): void
    {
        Mail::fake();

        $this->artisan('chats:chaseup-mods')
            ->expectsOutputToContain('Notified 0 mod(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_user2mod_rooms(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        // User2User room — should be ignored
        $room = $this->createTestChatRoom($member, $mod, [
            'chattype' => ChatRoom::TYPE_USER2USER,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subDays(1),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_room_with_no_recent_activity(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Room with only an old message (> 2 days ago), no recent activity
        $room = $this->createTestChatRoom($member, $member, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subDays(5),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_room_where_mod_has_replied(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $room = $this->createTestChatRoom($member, $mod, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        // Member sent first message > 566400 seconds ago
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);
        // Mod replied recently (2 senders)
        $this->createTestChatMessage($room, $mod, [
            'date' => now()->subHours(1),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_room_where_conversation_too_recent(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $room = $this->createTestChatRoom($member, $member, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        // Member sent message recently (< 566400 seconds ago, but within 2 days for the outer query)
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subHours(12),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_freegle_group(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup(['type' => Group::TYPE_OTHER]);

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        $room = $this->createTestChatRoom($member, $mod, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_sole_sender_is_mod(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $room = $this->createTestChatRoom($mod, $mod, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $mod, [
            'date' => now()->subSeconds(600000),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_notifies_mods_for_eligible_chat(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        $room = $this->createTestChatRoom($member, $mod, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        // Old initial message from member, no mod reply, also has a recent message
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subHours(1),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->expectsOutputToContain('Notified 1 mod(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_notifies_all_mods_for_group(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod1 = $this->createTestUser();
        $mod2 = $this->createTestUser();
        $owner = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($mod1, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);
        $this->createMembership($mod2, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);
        $this->createMembership($owner, $group, [
            'role' => Membership::ROLE_OWNER,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        $room = $this->createTestChatRoom($member, $mod1, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subHours(1),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->expectsOutputToContain('Notified 3 mod(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(3);
    }

    public function test_skips_mods_without_email(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        // Mod with no email record
        $modNoEmail = $this->createTestUser();
        DB::table('users_emails')->where('userid', $modNoEmail->id)->delete();
        $this->createMembership($modNoEmail, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        // Mod with email
        $modWithEmail = $this->createTestUser();
        $this->createMembership($modWithEmail, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        $room = $this->createTestChatRoom($member, $modWithEmail, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subHours(1),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->expectsOutputToContain('Notified 1 mod(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_deleted_mods(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        // Deleted mod
        $deletedMod = $this->createTestUser(['deleted' => now()]);
        $this->createMembership($deletedMod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        $room = $this->createTestChatRoom($member, $deletedMod, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subHours(1),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_emails(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        $room = $this->createTestChatRoom($member, $mod, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => $group->id,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subHours(1),
        ]);

        $this->artisan('chats:chaseup-mods', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would notify 1 mod(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_room_with_no_groupid(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        // Room without a groupid
        $room = $this->createTestChatRoom($member, $member, [
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'groupid' => null,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subSeconds(600000),
        ]);

        $this->artisan('chats:chaseup-mods')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
