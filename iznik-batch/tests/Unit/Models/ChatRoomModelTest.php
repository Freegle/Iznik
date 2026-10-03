<?php

namespace Tests\Unit\Models;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Tests\TestCase;

class ChatRoomModelTest extends TestCase
{
    public function test_chat_room_can_be_created(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $this->assertDatabaseHas('chat_rooms', ['id' => $room->id]);
    }

    public function test_user2user_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $user2user = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $user2mod = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user1->id,
            'created' => now(),
        ]);

        $rooms = ChatRoom::user2User()->get();

        $this->assertTrue($rooms->contains('id', $user2user->id));
        $this->assertFalse($rooms->contains('id', $user2mod->id));
    }

    public function test_user2mod_scope(): void
    {
        $user1 = $this->createTestUser();

        $user2mod = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user1->id,
            'created' => now(),
        ]);

        $rooms = ChatRoom::user2Mod()->get();

        $this->assertTrue($rooms->contains('id', $user2mod->id));
    }

    public function test_mod2mod_scope(): void
    {
        $mod2mod = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'created' => now(),
        ]);

        $rooms = ChatRoom::mod2Mod()->get();

        $this->assertTrue($rooms->contains('id', $mod2mod->id));
    }

    public function test_not_flagged_spam_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $clean = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
            'flaggedspam' => 0,
        ]);

        $spam = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user3->id,
            'created' => now(),
            'flaggedspam' => 1,
        ]);

        $rooms = ChatRoom::notFlaggedSpam()->get();

        $this->assertTrue($rooms->contains('id', $clean->id));
        $this->assertFalse($rooms->contains('id', $spam->id));
    }

    public function test_recent_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $recent = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
            'latestmessage' => now()->subDays(5),
        ]);

        $old = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user3->id,
            'created' => now()->subDays(60),
            'latestmessage' => now()->subDays(60),
        ]);

        $rooms = ChatRoom::recent(31)->get();

        $this->assertTrue($rooms->contains('id', $recent->id));
        $this->assertFalse($rooms->contains('id', $old->id));
    }

    public function test_get_other_user_returns_user2_when_user1_provided(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $other = $room->getOtherUser($user1->id);

        $this->assertInstanceOf(User::class, $other);
        $this->assertEquals($user2->id, $other->id);
    }

    public function test_get_other_user_returns_user1_when_user2_provided(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $other = $room->getOtherUser($user2->id);

        $this->assertInstanceOf(User::class, $other);
        $this->assertEquals($user1->id, $other->id);
    }

    public function test_get_other_user_returns_null_for_uninvolved_user(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $other = $room->getOtherUser($user3->id);

        $this->assertNull($other);
    }

    public function test_is_dm(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $user2user = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $user2mod = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user1->id,
            'created' => now(),
        ]);

        $this->assertTrue($user2user->isDm());
        $this->assertFalse($user2mod->isDm());
    }

    public function test_involves_user(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $this->assertTrue($room->involvesUser($user1->id));
        $this->assertTrue($room->involvesUser($user2->id));
        $this->assertFalse($room->involvesUser($user3->id));
    }

    public function test_messages_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Test',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertEquals(1, $room->messages()->count());
    }

    public function test_user1_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $this->assertEquals($user1->id, $room->user1()->first()->id);
    }

    public function test_user2_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $this->assertEquals($user2->id, $room->user2()->first()->id);
    }

    public function test_roster_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Test that the relationship returns a HasMany instance.
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $room->roster());
    }

    public function test_images_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Test that the relationship returns a HasMany instance.
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $room->images());
    }

    public function test_type_constants(): void
    {
        $this->assertEquals('Mod2Mod', ChatRoom::TYPE_MOD2MOD);
        $this->assertEquals('User2Mod', ChatRoom::TYPE_USER2MOD);
        $this->assertEquals('User2User', ChatRoom::TYPE_USER2USER);
    }

    public function test_get_or_create_user2mod_creates_new(): void
    {
        $user = $this->createTestUser();

        $chat = ChatRoom::getOrCreateUser2Mod($user->id);

        $this->assertNotNull($chat);
        $this->assertEquals(ChatRoom::TYPE_USER2MOD, $chat->chattype);
        $this->assertEquals($user->id, $chat->getAttributeValue('user1'));
    }

    public function test_get_or_create_user2mod_returns_existing(): void
    {
        $user = $this->createTestUser();

        $chat1 = ChatRoom::getOrCreateUser2Mod($user->id);
        $chat2 = ChatRoom::getOrCreateUser2Mod($user->id);

        $this->assertEquals($chat1->id, $chat2->id, 'Should return existing chat, not create duplicate');

        // Verify only one row exists.
        $count = ChatRoom::where('user1', $user->id)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->count();
        $this->assertEquals(1, $count, 'Should have exactly one User2Mod chat');
    }

    public function test_get_or_create_user2mod_adds_member_to_roster(): void
    {
        $user = $this->createTestUser();

        $chat = ChatRoom::getOrCreateUser2Mod($user->id);

        $this->assertDatabaseHas('chat_roster', [
            'chatid' => $chat->id,
            'userid' => $user->id,
        ]);
    }

    public function test_get_or_create_user2mod_adds_national_mods_to_roster(): void
    {
        $member = $this->createTestUser();
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $admin = $this->createTestUser(['systemrole' => User::SYSTEMROLE_ADMIN]);
        $nonMod = $this->createTestUser();

        $chat = ChatRoom::getOrCreateUser2Mod($member->id);

        $this->assertDatabaseHas('chat_roster', ['chatid' => $chat->id, 'userid' => $member->id]);
        $this->assertDatabaseHas('chat_roster', ['chatid' => $chat->id, 'userid' => $mod->id]);
        $this->assertDatabaseHas('chat_roster', ['chatid' => $chat->id, 'userid' => $admin->id]);
        $this->assertDatabaseMissing('chat_roster', ['chatid' => $chat->id, 'userid' => $nonMod->id]);
    }

    public function test_get_or_create_user2mod_adds_roster_for_new_mod_on_existing_chat(): void
    {
        $member = $this->createTestUser();

        // Create the chat first, before the new moderator exists.
        $chat = ChatRoom::getOrCreateUser2Mod($member->id);

        // Now a new national moderator appears, and the member messages Freegle again.
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $chat2 = ChatRoom::getOrCreateUser2Mod($member->id);

        $this->assertEquals($chat->id, $chat2->id);
        $this->assertDatabaseHas('chat_roster', ['chatid' => $chat->id, 'userid' => $member->id]);
        $this->assertDatabaseHas('chat_roster', ['chatid' => $chat->id, 'userid' => $mod->id]);
    }
}
