<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\EngageUpdateService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngageUpdateServiceTest extends TestCase
{
    protected EngageUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EngageUpdateService();
    }

    // --- NULL → New ---

    public function test_new_user_added_recently_gets_new_engagement(): void
    {
        $user = $this->createTestUser(['added' => now()->subDays(5)]);
        DB::table('users')->where('id', $user->id)->update(['engagement' => null]);

        $this->service->updateEngagement();

        $this->assertEquals('New', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- NULL → Inactive ---

    public function test_old_user_with_null_engagement_gets_inactive(): void
    {
        $user = $this->createTestUser(['added' => now()->subDays(60)]);
        DB::table('users')->where('id', $user->id)->update(['engagement' => null]);

        $this->service->updateEngagement();

        $this->assertEquals('Inactive', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- New/Occasional → Inactive (no access in 14 days) ---

    public function test_new_user_not_accessed_in_14_days_becomes_inactive(): void
    {
        $user = $this->createTestUser(['added' => now()->subDays(5)]);
        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'New',
            'lastaccess' => now()->subDays(15),
        ]);

        $this->service->updateEngagement();

        $this->assertEquals('Inactive', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    public function test_occasional_user_not_accessed_recently_becomes_inactive(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Occasional',
            'lastaccess' => now()->subDays(20),
        ]);

        $this->service->updateEngagement();

        $this->assertEquals('Inactive', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- Inactive → Dormant (not active for 182+ days) ---

    public function test_long_inactive_user_becomes_dormant(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Inactive',
            'lastaccess' => now()->subDays(200),
        ]);

        $this->service->updateEngagement();

        $this->assertEquals('Dormant', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    public function test_recently_inactive_user_does_not_become_dormant(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Inactive',
            'lastaccess' => now()->subDays(10),
        ]);

        $this->service->updateEngagement();

        $this->assertEquals('Inactive', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- New/Inactive/Dormant → Occasional (recently active with post/reply) ---

    public function test_inactive_user_with_recent_chat_message_becomes_occasional(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Inactive',
            'lastaccess' => now()->subDays(3),
        ]);

        // Create a chat room and message to trigger lastPostOrReply.
        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $user->id,
            'user2' => $user->id,
        ]);
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $user->id,
            'message' => 'test',
            'type' => 'Default',
            'date' => now()->subDays(5),
            'processingrequired' => 0,
        ]);

        $this->service->updateEngagement();

        $this->assertEquals('Occasional', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    public function test_inactive_user_with_recent_message_post_becomes_occasional(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Inactive',
            'lastaccess' => now()->subDays(3),
        ]);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: sofa (London)',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        $this->service->updateEngagement();

        $this->assertEquals('Occasional', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    public function test_inactive_user_with_no_recent_activity_stays_inactive(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Inactive',
            'lastaccess' => now()->subDays(3),
        ]);
        // No posts or chat messages.

        $this->service->updateEngagement();

        $this->assertEquals('Inactive', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- Occasional → Frequent (> 3 posts in 90 days) ---

    public function test_occasional_user_with_many_posts_becomes_frequent(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Occasional',
            'lastaccess' => now()->subDays(3),
        ]);

        // Create 4 messages between 35-38 days ago: > 3 in 90 days but < 4 in 31 days.
        for ($i = 0; $i < 4; $i++) {
            $message = Message::create([
                'type' => Message::TYPE_OFFER,
                'fromuser' => $user->id,
                'subject' => "OFFER: item{$i} (London)",
                'textbody' => "Item {$i}.",
                'source' => 'Platform',
                'date' => now()->subDays(35 + $i),
                'arrival' => now()->subDays(35 + $i),
                'lat' => $group->lat,
                'lng' => $group->lng,
            ]);
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(35 + $i),
            ]);
        }

        $this->service->updateEngagement();

        $this->assertEquals('Frequent', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    public function test_occasional_user_with_few_posts_stays_occasional(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Occasional',
            'lastaccess' => now()->subDays(3),
        ]);

        // Only 2 messages — not enough to become Frequent.
        for ($i = 0; $i < 2; $i++) {
            $message = Message::create([
                'type' => Message::TYPE_OFFER,
                'fromuser' => $user->id,
                'subject' => "OFFER: item{$i} (London)",
                'textbody' => "Item {$i}.",
                'source' => 'Platform',
                'date' => now()->subDays(10 + $i),
                'arrival' => now()->subDays(10 + $i),
                'lat' => $group->lat,
                'lng' => $group->lng,
            ]);
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(10 + $i),
            ]);
        }

        $this->service->updateEngagement();

        $this->assertEquals('Occasional', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- Frequent → Obsessed (>= 4 posts in 31 days) ---

    public function test_frequent_user_with_many_recent_posts_becomes_obsessed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Frequent',
            'lastaccess' => now()->subDays(1),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $message = Message::create([
                'type' => Message::TYPE_OFFER,
                'fromuser' => $user->id,
                'subject' => "OFFER: item{$i} (London)",
                'textbody' => "Item {$i}.",
                'source' => 'Platform',
                'date' => now()->subDays(5 + $i),
                'arrival' => now()->subDays(5 + $i),
                'lat' => $group->lat,
                'lng' => $group->lng,
            ]);
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(5 + $i),
            ]);
        }

        $this->service->updateEngagement();

        $this->assertEquals('Obsessed', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- Obsessed → Frequent (lost activity) ---

    public function test_obsessed_user_with_few_recent_posts_becomes_frequent(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'engagement' => 'Obsessed',
            'lastaccess' => now()->subDays(1),
        ]);

        // Only 2 posts in 90 days — not enough to stay Obsessed.
        for ($i = 0; $i < 2; $i++) {
            $message = Message::create([
                'type' => Message::TYPE_OFFER,
                'fromuser' => $user->id,
                'subject' => "OFFER: item{$i} (London)",
                'textbody' => "Item {$i}.",
                'source' => 'Platform',
                'date' => now()->subDays(10 + $i),
                'arrival' => now()->subDays(10 + $i),
                'lat' => $group->lat,
                'lng' => $group->lng,
            ]);
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(10 + $i),
            ]);
        }

        $this->service->updateEngagement();

        $this->assertEquals('Frequent', DB::table('users')->where('id', $user->id)->value('engagement'));
    }

    // --- Return value ---

    public function test_returns_count_of_updated_users(): void
    {
        $user1 = $this->createTestUser(['added' => now()->subDays(5)]);
        $user2 = $this->createTestUser(['added' => now()->subDays(5)]);
        DB::table('users')->whereIn('id', [$user1->id, $user2->id])->update(['engagement' => null]);

        $count = $this->service->updateEngagement();

        $this->assertGreaterThanOrEqual(2, $count);
    }
}
