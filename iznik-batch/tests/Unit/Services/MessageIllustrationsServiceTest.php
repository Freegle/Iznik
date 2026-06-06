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

    // AssertFlip: suppressed and rejected AI images must NOT be attached to messages.
    // Without ->where('status', 'active') these tests fail (attachment is created),
    // proving the bug. After the fix both tests pass.

    public function test_does_not_attach_suppressed_illustration(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Abstract Widget (TestTown)');

        DB::table('ai_images')->insert([
            'name' => 'Abstract Widget',
            'externaluid' => 'freegletusd-suppressed001',
            'imagehash' => 'hashsup001',
            'status' => 'suppressed',
        ]);

        $service = $this->makeService();
        $service->processIllustrations();

        $count = DB::table('messages_attachments')->where('msgid', $message->id)->count();
        $this->assertEquals(0, $count, 'Should not attach a suppressed AI illustration');
    }

    public function test_does_not_attach_rejected_illustration(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Broken Lamp (TestTown)');

        DB::table('ai_images')->insert([
            'name' => 'Broken Lamp',
            'externaluid' => 'freegletusd-rejected001',
            'imagehash' => 'hashrej001',
            'status' => 'rejected',
        ]);

        $service = $this->makeService();
        $service->processIllustrations();

        $count = DB::table('messages_attachments')->where('msgid', $message->id)->count();
        $this->assertEquals(0, $count, 'Should not attach a rejected AI illustration');
    }

    // AssertFlip: existing messages with suppressed/rejected AI attachments must have
    // those attachments removed by cleanupNonActiveAttachments so that no ghost blank
    // image slot is shown (topic 9753). Without the cleanup these tests fail (attachment
    // remains), proving the bug. After the fix both tests pass.

    public function test_removes_existing_suppressed_illustration_attachment(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Voucher (TestTown)');

        DB::table('ai_images')->insert([
            'name'        => 'Voucher',
            'externaluid' => 'freegletusd-sup-ghost001',
            'imagehash'   => 'hashsupghost001',
            'status'      => 'suppressed',
        ]);

        // Simulate an attachment created before the illustration was suppressed.
        $attachId = DB::table('messages_attachments')->insertGetId([
            'msgid'        => $message->id,
            'externaluid'  => 'freegletusd-sup-ghost001',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype'  => 'image/jpeg',
        ]);

        $service = $this->makeService();
        $result = $service->processIllustrations();

        $this->assertFalse(
            DB::table('messages_attachments')->where('id', $attachId)->exists(),
            'Suppressed illustration attachment must be removed to prevent ghost blank slot'
        );
        $this->assertGreaterThanOrEqual(1, $result['cleaned_non_active']);
    }

    public function test_removes_existing_rejected_illustration_attachment(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Printer (TestTown)');

        DB::table('ai_images')->insert([
            'name'        => 'Printer',
            'externaluid' => 'freegletusd-rej-ghost001',
            'imagehash'   => 'hashrejghost001',
            'status'      => 'rejected',
        ]);

        // Simulate an attachment created before the illustration was rejected.
        $attachId = DB::table('messages_attachments')->insertGetId([
            'msgid'        => $message->id,
            'externaluid'  => 'freegletusd-rej-ghost001',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype'  => 'image/jpeg',
        ]);

        $service = $this->makeService();
        $result = $service->processIllustrations();

        $this->assertFalse(
            DB::table('messages_attachments')->where('id', $attachId)->exists(),
            'Rejected illustration attachment must be removed to prevent ghost blank slot'
        );
        $this->assertGreaterThanOrEqual(1, $result['cleaned_non_active']);
    }

    public function test_does_not_remove_active_illustration_attachment(): void
    {
        $message = $this->createMessageInSpatial('OFFER: Chair (TestTown)');

        DB::table('ai_images')->insert([
            'name'        => 'Chair',
            'externaluid' => 'freegletusd-active-chair001',
            'imagehash'   => 'hashchairactive001',
            'status'      => 'active',
        ]);

        // Attach the active illustration.
        $attachId = DB::table('messages_attachments')->insertGetId([
            'msgid'        => $message->id,
            'externaluid'  => 'freegletusd-active-chair001',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype'  => 'image/jpeg',
        ]);

        $service = $this->makeService();
        $result = $service->processIllustrations();

        $this->assertTrue(
            DB::table('messages_attachments')->where('id', $attachId)->exists(),
            'Active illustration attachment must NOT be removed'
        );
        $this->assertEquals(0, $result['cleaned_non_active']);
    }
}
