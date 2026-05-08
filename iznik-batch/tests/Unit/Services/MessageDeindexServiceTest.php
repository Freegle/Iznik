<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\MessageDeindexService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageDeindexServiceTest extends TestCase
{
    protected MessageDeindexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageDeindexService();
        DB::table('messages_index')->delete();
        DB::table('words_cache')->delete();
        DB::table('words')->insertOrIgnore(['word' => 'testword', 'firstthree' => 'tes', 'soundex' => 'T363']);
        $this->wordId = DB::table('words')->where('word', 'testword')->value('id');
    }

    public function test_deindexes_messages_older_than_30_days(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: old sofa (London)',
            'textbody' => 'Old sofa.',
            'source' => 'Platform',
            'date' => now()->subDays(31),
            'arrival' => now()->subDays(31),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(31),
        ]);

        // Seed index entry for this old message.
        DB::table('messages_index')->insert([
            'msgid' => $message->id,
            'wordid' => $this->wordId,
            'arrival' => -now()->subDays(31)->timestamp,
            'groupid' => $group->id,
        ]);

        $result = $this->service->deindexOldMessages();

        $this->assertEquals(0, DB::table('messages_index')->where('msgid', $message->id)->count());
        $this->assertGreaterThanOrEqual(1, $result['deindexed']);
    }

    public function test_recent_messages_not_deindexed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: recent chair (London)',
            'textbody' => 'Recent chair.',
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

        DB::table('messages_index')->insert([
            'msgid' => $message->id,
            'wordid' => $this->wordId,
            'arrival' => -now()->subDays(5)->timestamp,
            'groupid' => $group->id,
        ]);

        $this->service->deindexOldMessages();

        $this->assertEquals(1, DB::table('messages_index')->where('msgid', $message->id)->count());
    }

    public function test_words_cache_cleared(): void
    {
        DB::table('words_cache')->insert(['search' => 'sofa', 'words' => '1,2,3']);

        $this->service->deindexOldMessages();

        $this->assertEquals(0, DB::table('words_cache')->count());
    }

    public function test_returns_zero_when_nothing_to_deindex(): void
    {
        $result = $this->service->deindexOldMessages();

        $this->assertEquals(0, $result['deindexed']);
    }
}
