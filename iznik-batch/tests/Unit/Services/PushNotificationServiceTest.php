<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for PushNotificationService::getBadgeCount().
 *
 * Each test creates its own mod + group + membership and only queries that mod's count.
 * Test isolation is provided by DatabaseTransactions in the base TestCase (each test
 * rolls back all DB changes). No shared state between tests.
 */
class PushNotificationServiceTest extends TestCase
{
    protected PushNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PushNotificationService();
    }

    /**
     * Held pending messages must NOT count towards the badge.
     *
     * Regression for Discourse #9547: mods reported a badge count of 1 but no
     * actionable work. Root cause: held pending messages were counted in the
     * badge but session.go's work total excludes heldby IS NOT NULL messages.
     */
    public function test_held_pending_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => $mod->id,  // held — must not count
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Held pending messages must not inflate badge count');
    }

    /**
     * Unheld pending messages DO count towards the badge.
     */
    public function test_unheld_pending_messages_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,  // not held — must count
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(1, $count, 'Unheld pending messages must count towards badge');
    }

    /**
     * Spam collection messages count towards the badge.
     *
     * Session.go uses COLLECTION_SPAM, not spamtype in Pending.
     */
    public function test_spam_collection_messages_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Spam (Location)',
            'textbody' => 'Spam',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_SPAM,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(1, $count, 'Spam collection messages must count towards badge');
    }

    /**
     * Deleted pending messages must NOT count towards the badge.
     */
    public function test_deleted_pending_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Deleted (Location)',
            'textbody' => 'Deleted',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 1,  // deleted — must not count
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Deleted messages must not inflate badge count');
    }

    /**
     * Messages with null fromuser must NOT count towards the badge.
     */
    public function test_null_fromuser_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $message = Message::create([
            'fromuser' => null,  // no sender — must not count
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: System (Location)',
            'textbody' => 'System',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Messages with null fromuser must not inflate badge count');
    }

    /**
     * Pending messages in inactive groups must NOT count towards the badge.
     *
     * Session.go excludes inactive group work from `total` (it goes to pendingother/blue).
     * A mod can set themselves inactive via membership settings.active=0.
     */
    public function test_inactive_group_pending_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => 0],  // inactive — must not count (Membership model has array cast)
        ]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Inactive group pending messages must not inflate badge count');
    }

    public function test_pending_volunteering_ops_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Create a pending volunteering op linked to this group.
        $volunteeringId = DB::table('volunteering')->insertGetId([
            'pending' => 1,
            'deleted' => 0,
            'expired' => 0,
            'title' => 'Test volunteer op',
            'userid' => $mod->id,
        ]);
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volunteeringId,
            'groupid' => $group->id,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(1, $count, 'Pending volunteering op must count towards badge');
    }

    public function test_non_pending_volunteering_ops_do_not_count(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Approved (non-pending) volunteering op — should not inflate badge.
        $volunteeringId = DB::table('volunteering')->insertGetId([
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'title' => 'Approved op',
            'userid' => $mod->id,
        ]);
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volunteeringId,
            'groupid' => $group->id,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Non-pending volunteering op must not count towards badge');
    }
}
