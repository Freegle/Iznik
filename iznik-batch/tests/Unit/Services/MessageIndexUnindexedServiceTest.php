<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\MessageIndexUnindexedService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageIndexUnindexedServiceTest extends TestCase
{
    protected MessageIndexUnindexedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageIndexUnindexedService();
        DB::table('messages_index')->delete();
        DB::table('messages_groups')->delete();
        DB::table('words_cache')->delete();
    }

    public function test_indexes_recent_unindexed_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: bicycle (London)',
            'textbody' => 'A bicycle.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(3),
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertGreaterThanOrEqual(1, $result['indexed']);
        $this->assertGreaterThan(0, DB::table('messages_index')->where('msgid', $message->id)->count());
    }

    public function test_does_not_reindex_already_indexed_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: table (London)',
            'textbody' => 'A table.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(3),
        ]);

        // Pre-seed an index entry.
        DB::table('words')->insertOrIgnore(['word' => 'table', 'firstthree' => 'tab', 'soundex' => 'T140']);
        $wordId = DB::table('words')->where('word', 'table')->value('id');
        DB::table('messages_index')->insert([
            'msgid' => $message->id,
            'wordid' => $wordId,
            'arrival' => -now()->subDays(3)->timestamp,
            'groupid' => $group->id,
        ]);

        $result = $this->service->indexUnindexedMessages();

        // Already indexed — should not be in the "indexed" count.
        $this->assertEquals(0, $result['indexed']);
    }

    public function test_parses_subject_to_index_item_not_type(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Subject "OFFER: widget (London)" — item is "widget", type is "offer", location is "london"
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: widget (London)',
            'textbody' => 'A widget.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(3),
        ]);

        $this->service->indexUnindexedMessages();

        // "widget" should be indexed
        $widgetId = DB::table('words')->where('word', 'widget')->value('id');
        $this->assertNotNull($widgetId);
        $this->assertGreaterThan(0, DB::table('messages_index')
            ->where('msgid', $message->id)
            ->where('wordid', $widgetId)
            ->count());

        // "offer" should NOT be indexed (it's a common/type word — actually "offer" is in the $common list)
        $offerId = DB::table('words')->where('word', 'offer')->value('id');
        if ($offerId) {
            $this->assertEquals(0, DB::table('messages_index')
                ->where('msgid', $message->id)
                ->where('wordid', $offerId)
                ->count());
        }
    }

    public function test_old_messages_not_indexed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: old lamp (London)',
            'textbody' => 'An old lamp.',
            'source' => 'Platform',
            'date' => now()->subDays(40),
            'arrival' => now()->subDays(40),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(40),
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertEquals(0, DB::table('messages_index')->where('msgid', $message->id)->count());
    }

    public function test_deleted_messages_not_indexed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: chair (London)',
            'textbody' => 'A chair.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(3),
            'deleted' => 1,
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertEquals(0, DB::table('messages_index')->where('msgid', $message->id)->count());
    }
}
