<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\MessageIllustrationsService;
use App\Services\PollinationsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageIllustrationsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('config')->where('key', 'illustrations_last_arrival')->delete();
        DB::table('config')->where('key', 'illustrations_cleanup_last_id')->delete();
        DB::table('messages_ai_declined')->delete();
        DB::table('messages_attachments')->delete();
        DB::table('messages_spatial')->delete();
        DB::table('ai_images')->delete();
    }

    private function makeService(?object $mockPollinations = null): MessageIllustrationsService
    {
        $mock = $mockPollinations ?? $this->makeMockPollinations();

        return new MessageIllustrationsService($mock);
    }

    private function makeMockPollinations(array $fetchResult = null): object
    {
        $mock = $this->createMock(PollinationsService::class);
        $mock->method('shouldSkipItem')->willReturn(false);
        $mock->method('recordFailure')->willReturn(false);
        $mock->method('fetchBatch')->willReturn($fetchResult ?? ['results' => [], 'failed' => []]);
        $mock->method('buildMessagePrompt')->willReturnCallback(fn ($n) => "prompt for $n");
        $mock->method('uploadImageAndCache')->willReturn('freegletusd-testuid123');

        return $mock;
    }

    private function createMessageInSpatial(string $subject = 'OFFER: Test Lamp (TestTown)'): object
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => $subject,
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now()->subMinutes(10),
            'arrival' => now()->subMinutes(10),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subMinutes(10),
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, 'Offer', now()->subMinutes(10)]
        );

        return $message;
    }

    public function test_processes_message_using_cached_illustration(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Vintage Lamp (TestTown)');

        DB::table('ai_images')->insert([
            'name' => 'Vintage Lamp',
            'externaluid' => 'freegletusd-cached999',
            'imagehash' => 'abc123',
        ]);

        $service = $this->makeService();
        $result = $service->processIllustrations();

        $attachment = DB::table('messages_attachments')->where('msgid', $message->id)->first();
        $this->assertNotNull($attachment, 'Expected attachment to be created for message');
        $this->assertEquals('freegletusd-cached999', $attachment->externaluid);
        $externalmods = json_decode($attachment->externalmods, true);
        $this->assertTrue($externalmods['ai']);
        $this->assertGreaterThanOrEqual(1, $result['processed']);
    }

    public function test_courtesy_word_in_subject_reuses_clean_cached_illustration(): void
    {
        // Discourse topic 9209/98: "WANTED: iron please" generated its own illustration for
        // the item "iron please" instead of reusing the good cached "iron" one.
        $message = $this->createMessageInSpatial('WANTED: Vintage Lamp please (TestTown)');

        DB::table('ai_images')->insert([
            'name' => 'Vintage Lamp',
            'externaluid' => 'freegletusd-cached999',
            'imagehash' => 'abc123',
        ]);

        $service = $this->makeService();
        $service->processIllustrations();

        $attachment = DB::table('messages_attachments')->where('msgid', $message->id)->first();
        $this->assertNotNull($attachment, 'Expected the clean cached illustration to be reused');
        $this->assertEquals('freegletusd-cached999', $attachment->externaluid);
    }

    public function test_stops_when_a_pass_creates_no_attachment(): void
    {
        // The candidate query is inclusive of $lastArrival, so a message that ends up with no
        // attachment is returned again by the next pass, unchanged. Generation that yields
        // neither an image nor a recorded failure therefore used to loop for ever, until MySQL
        // killed the query at its 30s execution cap. Nothing here is cached and the mocked
        // fetchBatch returns no results, so this test hangs unless the loop gives up.
        $message = $this->createMessageInSpatial('OFFER: Uncacheable Thing (TestTown)');

        $service = $this->makeService();
        $result = $service->processIllustrations();

        $count = DB::table('messages_attachments')->where('msgid', $message->id)->count();
        $this->assertEquals(0, $count, 'Nothing was generated, so no attachment should exist');
        $this->assertEquals(0, $result['processed']);
    }

    public function test_skips_message_in_declined_table(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Widget (TestTown)');

        DB::table('messages_ai_declined')->insert(['msgid' => $message->id]);

        $service = $this->makeService();
        $service->processIllustrations();

        $count = DB::table('messages_attachments')->where('msgid', $message->id)->count();
        $this->assertEquals(0, $count, 'Should not create attachment for declined message');
    }

    public function test_cleans_ai_attachment_when_user_adds_photo(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Kettle (TestTown)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        // AI attachment.
        $aiAttachId = DB::table('messages_attachments')->insertGetId([
            'msgid' => $message->id,
            'externaluid' => 'freegletusd-ai001',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype' => 'image/jpeg',
        ]);

        // User's own attachment.
        DB::table('messages_attachments')->insert([
            'msgid' => $message->id,
            'externaluid' => 'freegletusd-user001',
            'externalmods' => null,
            'contenttype' => 'image/jpeg',
            'primary' => 1,
        ]);

        $service = $this->makeService();
        $result = $service->processIllustrations();

        $this->assertGreaterThanOrEqual(1, $result['cleaned']);
        $this->assertFalse(
            DB::table('messages_attachments')->where('id', $aiAttachId)->exists(),
            'AI attachment should have been deleted'
        );
        $this->assertTrue(
            DB::table('messages_attachments')->where('externaluid', 'freegletusd-user001')->exists(),
            'User attachment should remain'
        );
    }

    public function test_updates_last_arrival_in_config(): void
    {
        $this->createMessageInSpatial('OFFER: Toaster (TestTown)');

        DB::table('ai_images')->insert([
            'name' => 'Toaster',
            'externaluid' => 'freegletusd-toaster',
            'imagehash' => 'hash001',
        ]);

        $service = $this->makeService();
        $service->processIllustrations();

        $lastArrival = DB::table('config')->where('key', 'illustrations_last_arrival')->value('value');
        $this->assertNotNull($lastArrival, 'Config should have last arrival set after processing');
    }

    /**
     * Build a message with the given attachments. Returns [messageId, attachmentIds].
     *
     * @param  array<int, array{ai: bool, uid: string}>  $attachments
     * @return array{0: int, 1: array<string, int>}
     */
    private function messageWithAttachments(array $attachments): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Kettle (TestTown)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        $ids = [];
        foreach ($attachments as $attachment) {
            $ids[$attachment['uid']] = DB::table('messages_attachments')->insertGetId([
                'msgid' => $message->id,
                'externaluid' => $attachment['uid'],
                'externalmods' => $attachment['ai'] ? json_encode(['ai' => true]) : null,
                'contenttype' => 'image/jpeg',
            ]);
        }

        return [$message->id, $ids];
    }

    /**
     * Record the cleanup query's SQL.
     *
     * @return array<int, array{sql: string, bindings: array}>
     */
    private function captureCleanupQueries(callable $fn): array
    {
        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            if (stripos($query->sql, 'ma_ai') !== false) {
                $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $fn();

        return $seen;
    }

    /**
     * The cleanup runs every minute and its current form drives a full scan of
     * messages_attachments (39.6M rows in production) to return, in the steady state, nothing.
     * It must be bounded by an id watermark.
     *
     * Both sides have to be bounded, not just the photo side. An illustration can be written
     * after the member's own photo - the generator races the upload - and a watermark on the
     * photo alone would leave that pair permanently invisible, because the photo's id is
     * already below the mark by the time the illustration arrives.
     */
    public function test_cleanup_is_bounded_by_a_watermark_on_both_sides(): void
    {
        $this->messageWithAttachments([
            ['ai' => true, 'uid' => 'freegletusd-ai-bound'],
            ['ai' => false, 'uid' => 'freegletusd-real-bound'],
        ]);

        $queries = $this->captureCleanupQueries(function () {
            $this->makeService()->processIllustrations();
        });

        $this->assertNotEmpty($queries, 'expected the cleanup query to run');
        $sql = $queries[0]['sql'];

        $this->assertMatchesRegularExpression(
            '/ma_real\.id\s*>\s*\?/i',
            $sql,
            'the photo side must be bounded so new photos are found by a primary-key range scan'
        );
        $this->assertMatchesRegularExpression(
            '/ma_ai\.id\s*>\s*\?/i',
            $sql,
            'the illustration side must be bounded too, or illustrations written after the photo are never cleaned up'
        );
    }

    /**
     * The case a photo-side-only watermark loses: the member's photo arrives and is consumed by
     * a run, and only then does the illustration land. The pair must still be cleaned up.
     */
    public function test_cleanup_removes_an_illustration_added_after_the_photo_watermark_passed(): void
    {
        [$msgid, $ids] = $this->messageWithAttachments([
            ['ai' => false, 'uid' => 'freegletusd-real-late'],
        ]);

        // First run consumes the photo and advances the watermark past it.
        $this->makeService()->processIllustrations();

        // The illustration lands afterwards, so its own id is above the mark but the photo's is not.
        $aiId = DB::table('messages_attachments')->insertGetId([
            'msgid' => $msgid,
            'externaluid' => 'freegletusd-ai-late',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype' => 'image/jpeg',
        ]);

        $this->makeService()->processIllustrations();

        $this->assertFalse(
            DB::table('messages_attachments')->where('id', $aiId)->exists(),
            'the illustration must be cleaned up even though the photo predates the watermark'
        );
        $this->assertTrue(
            DB::table('messages_attachments')->where('id', $ids['freegletusd-real-late'])->exists(),
            'the photo must remain'
        );
    }

    /**
     * A message that has only an illustration keeps it - the cleanup exists to drop the
     * illustration once a real photo turns up, not before.
     */
    public function test_cleanup_keeps_an_illustration_when_there_is_no_photo(): void
    {
        [, $ids] = $this->messageWithAttachments([
            ['ai' => true, 'uid' => 'freegletusd-ai-only'],
        ]);

        $this->makeService()->processIllustrations();

        $this->assertTrue(
            DB::table('messages_attachments')->where('id', $ids['freegletusd-ai-only'])->exists(),
            'an illustration with no competing photo must be left alone'
        );
    }
}
