<?php

namespace Tests\Unit\Models;

use App\Models\Membership;
use App\Models\User;
use App\Models\UserEmail;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    public function test_user_has_email_preferred_attribute(): void
    {
        $user = $this->createTestUser();

        $this->assertNotNull($user->email_preferred);
        $this->assertStringContainsString('@test.com', $user->email_preferred);
    }

    public function test_user_can_have_multiple_emails(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('primary'),
            'preferred' => 1,
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('secondary'),
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertEquals(2, $user->emails()->count());
    }

    public function test_user_has_memberships_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $this->assertEquals(1, $user->memberships()->count());
    }

    public function test_user_display_name_returns_fullname(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $this->assertEquals('Test User', $user->display_name);
    }

    public function test_user_display_name_falls_back_to_firstname(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => null,
            'added' => now(),
        ]);

        $this->assertEquals('Test User', $user->display_name);
    }

    public function test_user_display_name_falls_back_to_default(): void
    {
        $user = User::create([
            'firstname' => null,
            'lastname' => null,
            'fullname' => null,
            'added' => now(),
        ]);

        $this->assertEquals('Freegle User', $user->display_name);
    }

    public function test_user_is_moderator_returns_false_for_regular_user(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->assertFalse($user->isModerator());
    }

    public function test_user_is_moderator_returns_true_for_moderator(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);

        $this->assertTrue($user->isModerator());
    }

    public function test_user_is_moderator_returns_true_for_owner(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_OWNER]);

        $this->assertTrue($user->isModerator());
    }

    public function test_user_is_moderator_of_returns_false_for_other_group(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $this->createMembership($user, $group1, ['role' => Membership::ROLE_MODERATOR]);

        $this->assertTrue($user->isModeratorOf($group1->id));
        $this->assertFalse($user->isModeratorOf($group2->id));
    }

    public function test_user_donations_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->donations()->count());
    }

    public function test_user_messages_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $this->createTestMessage($user, $group);

        $this->assertEquals(1, $user->messages()->count());
    }

    public function test_user_chat_messages_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->chatMessages()->count());
    }

    public function test_user_notifications_relationship_exists(): void
    {
        $user = $this->createTestUser();

        // Verify the relationship method exists and is callable.
        $this->assertTrue(method_exists($user, 'notifications'));
    }

    public function test_user_chat_rooms_as_user1_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->chatRoomsAsUser1()->count());
    }

    public function test_user_chat_rooms_as_user2_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->chatRoomsAsUser2()->count());
    }

    public function test_user_gift_aid_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertNull($user->giftAid);
    }

    public function test_remove_tn_group_strips_suffix(): void
    {
        $this->assertEquals('Alice', User::removeTNGroup('Alice-g298'));
        $this->assertEquals('Bob Smith', User::removeTNGroup('Bob Smith-g12345'));
    }

    public function test_remove_tn_group_preserves_name_without_suffix(): void
    {
        $this->assertEquals('Alice', User::removeTNGroup('Alice'));
        $this->assertEquals('Bob Smith', User::removeTNGroup('Bob Smith'));
    }

    public function test_remove_tn_group_preserves_hyphen_without_g_suffix(): void
    {
        $this->assertEquals('Mary-Jane', User::removeTNGroup('Mary-Jane'));
    }

    public function test_display_name_strips_tn_group_suffix(): void
    {
        $user = User::create([
            'fullname' => 'Alice-g298',
            'added' => now(),
        ]);

        $this->assertEquals('Alice', $user->display_name);
    }

    public function test_is_tn_returns_true_for_trashnothing_user(): void
    {
        $user = User::create([
            'fullname' => 'TN User',
            'added' => now(),
        ]);

        // Use unique prefix but TN domain to test isTN() detection.
        UserEmail::create([
            'userid' => $user->id,
            'email' => 'user_' . uniqid('', true) . '@user.trashnothing.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertTrue($user->fresh()->isTN());
    }

    public function test_is_tn_returns_false_for_regular_user(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse($user->isTN());
    }

    public function test_is_lj_returns_true_when_ljuserid_set(): void
    {
        $user = User::create([
            'fullname' => 'LJ User',
            'added' => now(),
            'ljuserid' => 12345,
        ]);

        $this->assertTrue($user->fresh()->isLJ());
    }

    public function test_is_lj_returns_false_when_ljuserid_null(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse($user->isLJ());
    }

    public function test_notifs_on_emailmine_always_false_for_tn_user(): void
    {
        // TN user with emailmine explicitly enabled in settings — the guard
        // must still return false because a self-copy is delivered back to
        // themselves via the TN proxy address.
        $user = User::create([
            'fullname' => 'TN User',
            'added' => now(),
            'settings' => [
                'notifications' => ['emailmine' => true],
            ],
        ]);
        UserEmail::create([
            'userid' => $user->id,
            'email' => 'user_'.uniqid('', true).'@user.trashnothing.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertFalse($user->fresh()->notifsOn(User::NOTIFS_EMAIL_MINE));
    }

    public function test_notifs_on_emailmine_always_false_for_lj_user(): void
    {
        // LJ user with emailmine explicitly enabled — same rationale as TN.
        $user = User::create([
            'fullname' => 'LJ User',
            'added' => now(),
            'ljuserid' => 67890,
            'settings' => [
                'notifications' => ['emailmine' => true],
            ],
        ]);

        $this->assertFalse($user->fresh()->notifsOn(User::NOTIFS_EMAIL_MINE));
    }

    public function test_notifs_on_email_still_honoured_for_tn_user(): void
    {
        // The TN/LJ guard only covers emailmine — ordinary email notifications
        // must still respect the stored setting.
        $user = User::create([
            'fullname' => 'TN User',
            'added' => now(),
            'settings' => [
                'notifications' => ['email' => false, 'emailmine' => true],
            ],
        ]);
        UserEmail::create([
            'userid' => $user->id,
            'email' => 'user_'.uniqid('', true).'@user.trashnothing.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertFalse($user->fresh()->notifsOn(User::NOTIFS_EMAIL));
    }

    public function test_notifs_on_returns_default_true_for_email(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
        ]);

        $this->assertTrue($user->notifsOn(User::NOTIFS_EMAIL));
    }

    public function test_notifs_on_returns_default_false_for_emailmine(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
        ]);

        $this->assertFalse($user->notifsOn(User::NOTIFS_EMAIL_MINE));
    }

    public function test_notifs_on_returns_default_true_for_push(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
        ]);

        $this->assertTrue($user->notifsOn(User::NOTIFS_PUSH));
    }

    public function test_notifs_on_respects_user_settings(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
            'settings' => [
                'notifications' => [
                    'email' => false,
                    'emailmine' => true,
                    'push' => false,
                ],
            ],
        ]);

        $this->assertFalse($user->notifsOn(User::NOTIFS_EMAIL));
        $this->assertTrue($user->notifsOn(User::NOTIFS_EMAIL_MINE));
        $this->assertFalse($user->notifsOn(User::NOTIFS_PUSH));
    }

    public function test_notifs_on_with_group_checks_moderator_status(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Should return true because user is a moderator of the group.
        $this->assertTrue($user->notifsOn(User::NOTIFS_EMAIL, $group->id));
    }

    public function test_notifs_on_with_group_returns_false_for_non_moderator(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        // Should return false because user is not a moderator.
        $this->assertFalse($user->notifsOn(User::NOTIFS_EMAIL, $group->id));
    }

    public function test_get_lat_lng_returns_null_without_last_location(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
            'lastlocation' => null,
        ]);

        [$lat, $lng] = $user->getLatLng();

        $this->assertNull($lat);
        $this->assertNull($lng);
    }

    public function test_get_job_ads_returns_empty_collection_without_location(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
            'lastlocation' => null,
        ]);

        $result = $user->getJobAds();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('jobs', $result);
        $this->assertArrayHasKey('location', $result);
        $this->assertTrue($result['jobs']->isEmpty());
        $this->assertNull($result['location']);
    }

    public function test_get_profile_image_url_returns_null_without_image(): void
    {
        $user = $this->createTestUser();

        $result = $user->getProfileImageUrl();

        $this->assertNull($result);
    }

    public function test_get_user_key_creates_new_key(): void
    {
        $user = $this->createTestUser();

        $key = $user->getUserKey();

        $this->assertNotEmpty($key);
        $this->assertEquals(32, strlen($key)); // 16 bytes = 32 hex chars
    }

    public function test_get_user_key_returns_existing_key(): void
    {
        $user = $this->createTestUser();

        $key1 = $user->getUserKey();
        $key2 = $user->getUserKey();

        $this->assertEquals($key1, $key2);
    }

    public function test_list_unsubscribe_returns_formatted_url(): void
    {
        $user = $this->createTestUser();

        $result = $user->listUnsubscribe();

        $this->assertStringStartsWith('<', $result);
        $this->assertStringEndsWith('>', $result);
        $this->assertStringContainsString('/one-click-unsubscribe/', $result);
        $this->assertStringContainsString((string) $user->id, $result);
    }

    public function test_email_tracking_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->emailTracking()->count());
    }

    public function test_login_link_constant(): void
    {
        $this->assertEquals('Link', User::LOGIN_LINK);
    }

    /**
     * Tests for internal email filtering in getEmailPreferred.
     * These verify that iznik-batch matches iznik-server's behavior.
     */

    public function test_email_preferred_excludes_users_ilovefreegle_domain(): void
    {
        $user = User::create([
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Create an internal email marked as preferred.
        UserEmail::create([
            'userid' => $user->id,
            'email' => 'test-' . uniqid() . '@users.ilovefreegle.org',
            'preferred' => 1,
            'added' => now(),
        ]);

        // Create a real external email.
        $externalEmail = 'external-' . uniqid() . '@gmail.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $externalEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        // Should skip the internal email and return the external one.
        $this->assertEquals($externalEmail, $user->fresh()->email_preferred);
    }

    public function test_email_preferred_excludes_groups_ilovefreegle_domain(): void
    {
        $user = User::create([
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Create a groups.ilovefreegle.org email marked as preferred.
        UserEmail::create([
            'userid' => $user->id,
            'email' => 'groupname-' . uniqid() . '@groups.ilovefreegle.org',
            'preferred' => 1,
            'added' => now(),
        ]);

        // Create a real external email.
        $externalEmail = 'external-' . uniqid() . '@hotmail.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $externalEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertEquals($externalEmail, $user->fresh()->email_preferred);
    }

    public function test_email_preferred_excludes_direct_ilovefreegle_domain(): void
    {
        $user = User::create([
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'user-' . uniqid() . '@direct.ilovefreegle.org',
            'preferred' => 1,
            'added' => now(),
        ]);

        $externalEmail = 'external-' . uniqid() . '@yahoo.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $externalEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertEquals($externalEmail, $user->fresh()->email_preferred);
    }

    public function test_email_preferred_excludes_republisher_freegle_domain(): void
    {
        $user = User::create([
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'feed-' . uniqid() . '@republisher.freegle.in',
            'preferred' => 1,
            'added' => now(),
        ]);

        $externalEmail = 'external-' . uniqid() . '@outlook.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $externalEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertEquals($externalEmail, $user->fresh()->email_preferred);
    }

    public function test_email_preferred_excludes_yahoogroups_domain(): void
    {
        $user = User::create([
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'oldgroup-' . uniqid() . '@yahoogroups.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        $externalEmail = 'external-' . uniqid() . '@protonmail.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $externalEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertEquals($externalEmail, $user->fresh()->email_preferred);
    }

    public function test_email_preferred_returns_null_when_all_emails_are_internal(): void
    {
        $user = User::create([
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Only internal emails.
        UserEmail::create([
            'userid' => $user->id,
            'email' => 'user1-' . uniqid() . '@users.ilovefreegle.org',
            'preferred' => 1,
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'group1-' . uniqid() . '@groups.ilovefreegle.org',
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertNull($user->fresh()->email_preferred);
    }

    public function test_is_internal_email_detects_users_domain(): void
    {
        $this->assertTrue(User::isInternalEmail('test@users.ilovefreegle.org'));
        $this->assertTrue(User::isInternalEmail('TEST@USERS.ILOVEFREEGLE.ORG'));
    }

    public function test_is_internal_email_detects_groups_domain(): void
    {
        $this->assertTrue(User::isInternalEmail('testgroup@groups.ilovefreegle.org'));
    }

    public function test_is_internal_email_detects_direct_domain(): void
    {
        $this->assertTrue(User::isInternalEmail('user@direct.ilovefreegle.org'));
    }

    public function test_is_internal_email_detects_republisher_domain(): void
    {
        $this->assertTrue(User::isInternalEmail('feed@republisher.freegle.in'));
    }

    public function test_is_internal_email_detects_yahoogroups(): void
    {
        $this->assertTrue(User::isInternalEmail('group@yahoogroups.com'));
        $this->assertTrue(User::isInternalEmail('group@yahoogroups.co.uk'));
    }

    public function test_is_internal_email_allows_external_addresses(): void
    {
        $this->assertFalse(User::isInternalEmail('user@gmail.com'));
        $this->assertFalse(User::isInternalEmail('user@yahoo.com'));
        $this->assertFalse(User::isInternalEmail('user@hotmail.com'));
        $this->assertFalse(User::isInternalEmail('user@example.org'));
    }

    public function test_is_internal_email_is_case_insensitive(): void
    {
        $this->assertTrue(User::isInternalEmail('TEST@USERS.ILOVEFREEGLE.ORG'));
        $this->assertTrue(User::isInternalEmail('Test@Users.ILoveFreegle.Org'));
        $this->assertTrue(User::isInternalEmail('group@YAHOOGROUPS.COM'));
    }
}
