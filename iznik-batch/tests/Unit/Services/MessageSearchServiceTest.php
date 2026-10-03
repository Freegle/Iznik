<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Services\MessageSearchService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageSearchServiceTest extends TestCase
{
    protected MessageSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageSearchService;
        DB::table('messages_index')->delete();
        DB::table('words_cache')->delete();
        DB::table('words')->insertOrIgnore(['word' => 'testword', 'firstthree' => 'tes', 'soundex' => 'T363']);
        $this->wordId = DB::table('words')->where('word', 'testword')->value('id');
    }

    // --- deindexOldMessages ---

    public function test_deindexes_messages_older_than_30_days(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: old sofa (London)',
            'textbody' => 'Old sofa.',
            'source' => 'Platform',
            'date' => now()->subDays(31),
            'arrival' => now()->subDays(31),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::table('messages_index')->insert([
            'msgid' => $message->id,
            'wordid' => $this->wordId,
            'arrival' => -now()->subDays(31)->timestamp,
        ]);

        $result = $this->service->deindexOldMessages();

        $this->assertEquals(0, DB::table('messages_index')->where('msgid', $message->id)->count());
        $this->assertGreaterThanOrEqual(1, $result);
    }

    public function test_recent_messages_not_deindexed(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: recent chair (London)',
            'textbody' => 'Recent chair.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::table('messages_index')->insert([
            'msgid' => $message->id,
            'wordid' => $this->wordId,
            'arrival' => -now()->subDays(5)->timestamp,
        ]);

        $this->service->deindexOldMessages();

        $this->assertEquals(1, DB::table('messages_index')->where('msgid', $message->id)->count());
    }

    public function test_words_cache_cleared_after_deindex(): void
    {
        DB::table('words_cache')->insert(['search' => 'sofa', 'words' => '1,2,3']);

        $this->service->deindexOldMessages();

        $this->assertEquals(0, DB::table('words_cache')->count());
    }

    public function test_deindex_returns_zero_when_nothing_to_deindex(): void
    {
        $result = $this->service->deindexOldMessages();

        $this->assertEquals(0, $result);
    }

    // --- indexUnindexedMessages ---

    public function test_indexes_recent_unindexed_message(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: bicycle (London)',
            'textbody' => 'A bicycle.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertGreaterThanOrEqual(1, $result);
        $this->assertGreaterThan(0, DB::table('messages_index')->where('msgid', $message->id)->count());
    }

    public function test_does_not_reindex_already_indexed_message(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: table (London)',
            'textbody' => 'A table.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::table('words')->insertOrIgnore(['word' => 'table', 'firstthree' => 'tab', 'soundex' => 'T140']);
        $wordId = DB::table('words')->where('word', 'table')->value('id');
        DB::table('messages_index')->insert([
            'msgid' => $message->id,
            'wordid' => $wordId,
            'arrival' => -now()->subDays(3)->timestamp,
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertEquals(0, $result);
    }

    public function test_parses_subject_to_index_item_not_type(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: widget (London)',
            'textbody' => 'A widget.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        $this->service->indexUnindexedMessages();

        $widgetId = DB::table('words')->where('word', 'widget')->value('id');
        $this->assertNotNull($widgetId);
        $this->assertGreaterThan(0, DB::table('messages_index')
            ->where('msgid', $message->id)
            ->where('wordid', $widgetId)
            ->count());

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

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: old lamp (London)',
            'textbody' => 'An old lamp.',
            'source' => 'Platform',
            'date' => now()->subDays(40),
            'arrival' => now()->subDays(40),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertEquals(0, DB::table('messages_index')->where('msgid', $message->id)->count());
    }

    public function test_deleted_messages_not_indexed(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: chair (London)',
            'textbody' => 'A chair.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => 51.5074,
            'lng' => -0.1278,
            'collection' => Message::COLLECTION_APPROVED,
            'deleted' => now(),
        ]);

        $result = $this->service->indexUnindexedMessages();

        $this->assertEquals(0, DB::table('messages_index')->where('msgid', $message->id)->count());
    }
}
