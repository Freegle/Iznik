<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\MessageRemapSubjectsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageRemapSubjectsServiceTest extends TestCase
{
    protected MessageRemapSubjectsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageRemapSubjectsService();
    }

    public function test_updates_subject_when_location_name_changes(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create a location with old name, then update it.
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'New Town',
            'type' => 'Polygon',
            'lat' => 51.5,
            'lng' => -0.1,
            'timestamp' => now()->subHours(1),
        ]);

        // Create an item.
        DB::table('items')->insertOrIgnore(['name' => 'sofa']);
        $itemId = DB::table('items')->where('name', 'sofa')->value('id');

        // Create a message with OLD location name in subject.
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: sofa (Old Town)',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'locationid' => $locationId,
        ]);
        DB::table('messages_items')->insertOrIgnore(['msgid' => $message->id, 'itemid' => $itemId]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        $result = $this->service->remapSubjects();

        $updated = DB::table('messages')->where('id', $message->id)->value('subject');
        $this->assertStringContainsString('New Town', $updated);
        $this->assertGreaterThanOrEqual(1, $result['changed']);
    }

    public function test_skips_message_when_location_unchanged(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Same Town',
            'type' => 'Polygon',
            'lat' => 51.5,
            'lng' => -0.1,
            'timestamp' => now()->subHours(1),
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'chair']);
        $itemId = DB::table('items')->where('name', 'chair')->value('id');

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: chair (Same Town)',
            'textbody' => 'A chair.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'locationid' => $locationId,
        ]);
        DB::table('messages_items')->insertOrIgnore(['msgid' => $message->id, 'itemid' => $itemId]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        $result = $this->service->remapSubjects();

        $this->assertEquals(0, $result['changed']);
    }

    public function test_returns_zero_for_old_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Updated Area',
            'type' => 'Polygon',
            'lat' => 51.5,
            'lng' => -0.1,
            'timestamp' => now()->subHours(1),
        ]);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lamp (Old Area)',
            'textbody' => 'A lamp.',
            'source' => 'Platform',
            'date' => now()->subDays(91),
            'arrival' => now()->subDays(91),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'locationid' => $locationId,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(91),
        ]);

        $result = $this->service->remapSubjects();

        $this->assertEquals(0, $result['changed']);
    }
}
