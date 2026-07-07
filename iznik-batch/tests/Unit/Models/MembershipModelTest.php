<?php

namespace Tests\Unit\Models;

use App\Models\Membership;
use Tests\TestCase;

class MembershipModelTest extends TestCase
{
    public function test_membership_can_be_created(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $this->assertDatabaseHas('memberships', ['id' => $membership->id]);
    }

    public function test_approved_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $approved = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $pending = Membership::create([
            'userid' => $user->id,
            'groupid' => $this->createTestGroup()->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_PENDING,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $memberships = Membership::approved()->get();

        $this->assertTrue($memberships->contains('id', $approved->id));
        $this->assertFalse($memberships->contains('id', $pending->id));
    }

    public function test_pending_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $pending = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_PENDING,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $memberships = Membership::pending()->get();

        $this->assertTrue($memberships->contains('id', $pending->id));
    }

    public function test_moderators_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $mod = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $owner = Membership::create([
            'userid' => $this->createTestUser()->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $member = Membership::create([
            'userid' => $this->createTestUser()->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $mods = Membership::moderators()->get();

        $this->assertTrue($mods->contains('id', $mod->id));
        $this->assertTrue($mods->contains('id', $owner->id));
        $this->assertFalse($mods->contains('id', $member->id));
    }

    public function test_owners_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $owner = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $mod = Membership::create([
            'userid' => $this->createTestUser()->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $owners = Membership::owners()->get();

        $this->assertTrue($owners->contains('id', $owner->id));
        $this->assertFalse($owners->contains('id', $mod->id));
    }

    public function test_with_email_frequency_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $daily = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
            'added' => now(),
        ]);

        $hourly = Membership::create([
            'userid' => $this->createTestUser()->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_HOURLY,
            'added' => now(),
        ]);

        $dailyMembers = Membership::withEmailFrequency(Membership::EMAIL_FREQUENCY_DAILY)->get();

        $this->assertTrue($dailyMembers->contains('id', $daily->id));
        $this->assertFalse($dailyMembers->contains('id', $hourly->id));
    }

    public function test_digest_subscribers_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $dailyDigest = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
            'added' => now(),
        ]);

        $noDigest = Membership::create([
            'userid' => $this->createTestUser()->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_NEVER,
            'added' => now(),
        ]);

        $subscribers = Membership::digestSubscribers(Membership::EMAIL_FREQUENCY_DAILY)->get();

        $this->assertTrue($subscribers->contains('id', $dailyDigest->id));
        $this->assertFalse($subscribers->contains('id', $noDigest->id));
    }

    public function test_is_moderator(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $group = $this->createTestGroup();

        $modMembership = Membership::create([
            'userid' => $user1->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $ownerMembership = Membership::create([
            'userid' => $user2->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $memberMembership = Membership::create([
            'userid' => $user3->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $this->assertTrue($modMembership->isModerator());
        $this->assertTrue($ownerMembership->isModerator());
        $this->assertFalse($memberMembership->isModerator());
    }

    public function test_is_owner(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();

        $ownerMembership = Membership::create([
            'userid' => $user1->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $modMembership = Membership::create([
            'userid' => $user2->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ]);

        $this->assertTrue($ownerMembership->isOwner());
        $this->assertFalse($modMembership->isOwner());
    }

    public function test_get_setting(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
            'settings' => ['showmod' => true, 'modstatus' => 'Active'],
        ]);

        $this->assertTrue($membership->getSetting('showmod'));
        $this->assertEquals('Active', $membership->getSetting('modstatus'));
        $this->assertNull($membership->getSetting('nonexistent'));
        $this->assertEquals('default', $membership->getSetting('nonexistent', 'default'));
    }

    public function test_get_setting_with_null_settings(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
            'settings' => null,
        ]);

        $this->assertNull($membership->getSetting('anything'));
        $this->assertEquals('default', $membership->getSetting('anything', 'default'));
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = $this->createMembership($user, $group);

        $this->assertEquals($user->id, $membership->user->id);
    }

    public function test_group_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = $this->createMembership($user, $group);

        $this->assertEquals($group->id, $membership->group->id);
    }
}
