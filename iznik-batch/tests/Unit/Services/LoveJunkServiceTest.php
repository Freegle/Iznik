<?php

namespace Tests\Unit\Services;

use App\Services\LoveJunkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoveJunkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lovejunk')->delete();
        DB::table('messages_promises')->delete();
        DB::table('messages_outcomes')->delete();
        DB::table('messages_groups')->delete();
        DB::table('messages_items')->delete();
        DB::table('messages_attachments')->delete();
        DB::table('messages_edits')->delete();
        DB::table('messages')->delete();
        DB::table('chat_rooms')->delete();
        DB::table('items')->delete();
        DB::table('users')->delete();
        DB::table('locations')->delete();
        DB::table('groups')->update(['onlovejunk' => 0]);
    }

    private function createGroup(array $attrs = []): int
    {
        $nameshort = $attrs['nameshort'] ?? ('TestLJGroup_' . uniqid());
        $onlovejunk = $attrs['onlovejunk'] ?? 1;

        // polyindex is NOT NULL spatial — must be set in the INSERT statement
        DB::statement("
            INSERT INTO `groups`
                (nameshort, namefull, type, onlovejunk, onhere, publish, lat, lng, polyindex)
            VALUES
                (?, 'Test LoveJunk Group', 'Freegle', ?, 1, 1, 51.5, -0.1,
                 ST_GeomFromText('POLYGON((-1 51,-1 52,0 52,0 51,-1 51))',3857))
        ", [$nameshort, $onlovejunk]);

        return (int) DB::getPdo()->lastInsertId();
    }

    private function createUser(array $attrs = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ], $attrs));
    }

    private function createLocation(array $attrs = []): int
    {
        return DB::table('locations')->insertGetId(array_merge([
            'name' => 'SW1A 1AA',
            'type' => 'Postcode',
            'lat' => 51.5014,
            'lng' => -0.1419,
        ], $attrs));
    }

    private function createMessage(int $userId, int $groupId, int $locationId, array $attrs = []): int
    {
        $msgId = DB::table('messages')->insertGetId(array_merge([
            'fromuser' => $userId,
            'type' => 'Offer',
            'subject' => 'OFFER: Old sofa (London)',
            'textbody' => 'Free sofa in good condition.',
            'sourceheader' => 'freegle',
            'locationid' => $locationId,
            'lat' => 51.5014,
            'lng' => -0.1419,
            'arrival' => now(),
            'date' => now(),
        ], $attrs));

        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $groupId,
            'collection' => 'Approved',
            'arrival' => now(),
        ]);

        return $msgId;
    }

    private function fakeSuccessfulPost(array $body = []): void
    {
        Http::fake([
            '*/freegle/drafts*' => Http::response(json_encode(array_merge(['body' => ['draftId' => 'lj-draft-001']], $body)), 200),
        ]);
    }

    public function test_syncs_new_message_to_lovejunk(): void
    {
        $this->fakeSuccessfulPost();

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);

        // Add item name
        $itemId = DB::table('items')->insertGetId(['name' => 'Sofa', 'popularity' => 1, 'updated' => now()]);
        DB::table('messages_items')->insert(['msgid' => $msgId, 'itemid' => $itemId]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(1, $result['sent']);
        $this->assertEquals(0, $result['edited']);
        $this->assertEquals(0, $result['completed_or_deleted']);

        // Verify lovejunk table was updated
        $lj = DB::table('lovejunk')->where('msgid', $msgId)->first();
        $this->assertNotNull($lj);
        $this->assertEquals(1, $lj->success);
        $this->assertStringContainsString('lj-draft-001', $lj->status);
    }

    public function test_skips_message_already_sent_to_lovejunk(): void
    {
        Http::fake();

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);

        // Pre-insert lovejunk record (already sent)
        DB::table('lovejunk')->insert(['msgid' => $msgId, 'success' => 1, 'status' => '{"draftId":"existing"}']);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_skips_message_from_non_lovejunk_group(): void
    {
        Http::fake();

        $userId = $this->createUser();
        $groupId = $this->createGroup(['onlovejunk' => 0]);
        $locationId = $this->createLocation();
        $this->createMessage($userId, $groupId, $locationId);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_skips_message_without_postcode(): void
    {
        Http::fake();

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        // No locationId for this message
        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'type' => 'Offer',
            'subject' => 'OFFER: Chair (London)',
            'textbody' => 'Old chair.',
            'sourceheader' => 'freegle',
            'locationid' => null,
            'lat' => null,
            'lng' => null,
            'arrival' => now(),
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert(['msgid' => $msgId, 'groupid' => $groupId, 'collection' => 'Approved', 'arrival' => now()]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_handles_api_failure_gracefully(): void
    {
        Http::fake([
            '*/freegle/drafts*' => Http::response('Server Error', 500),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(1, $result['failed']);

        // Should record a failed entry
        $lj = DB::table('lovejunk')->where('msgid', $msgId)->first();
        $this->assertNotNull($lj);
        $this->assertEquals(0, $lj->success);
    }

    public function test_syncs_edited_message(): void
    {
        Http::fake([
            '*/freegle/drafts/lj-draft-001' => Http::response('', 200),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);

        // Pre-insert a successful lovejunk record
        DB::table('lovejunk')->insert([
            'msgid' => $msgId,
            'success' => 1,
            'status' => json_encode(['draftId' => 'lj-draft-001']),
            'timestamp' => now()->subHour(),
        ]);

        // Create an edit AFTER the lovejunk sync time
        DB::table('messages_edits')->insert([
            'msgid' => $msgId,
            'timestamp' => now(),
            'byuser' => $userId,
        ]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(1, $result['edited']);
    }

    public function test_deletes_message_with_outcome_no_promise(): void
    {
        Http::fake([
            '*/freegle/drafts/lj-draft-002*' => Http::response('', 200),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);

        DB::table('lovejunk')->insert([
            'msgid' => $msgId,
            'success' => 1,
            'status' => json_encode(['draftId' => 'lj-draft-002']),
        ]);

        // Add an outcome (message was taken/withdrawn)
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgId,
            'outcome' => 'Taken',
            'happiness' => null,
            'timestamp' => now(),
        ]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(1, $result['completed_or_deleted']);

        // Should mark as deleted in lovejunk table
        $lj = DB::table('lovejunk')->where('msgid', $msgId)->first();
        $this->assertNotNull($lj->deleted);
    }

    public function test_completes_offer_via_lj_promise(): void
    {
        Http::fake([
            '*/freegle/offers/888/complete' => Http::response('', 200),
        ]);

        $senderId = $this->createUser();
        $promiseeId = $this->createUser(['ljuserid' => 888]);
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($senderId, $groupId, $locationId);

        DB::table('lovejunk')->insert([
            'msgid' => $msgId,
            'success' => 1,
            'status' => json_encode(['draftId' => 'lj-draft-003']),
        ]);

        DB::table('messages_promises')->insert([
            'msgid' => $msgId,
            'userid' => $promiseeId,
        ]);

        DB::table('chat_rooms')->insert([
            'user1' => $senderId,
            'user2' => $promiseeId,
            'chattype' => 'User2User',
            'ljofferid' => 888,
        ]);

        DB::table('messages_outcomes')->insert([
            'msgid' => $msgId,
            'outcome' => 'Taken',
            'happiness' => null,
            'timestamp' => now(),
        ]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(1, $result['completed_or_deleted']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/freegle/offers/888/complete');
        });

        $lj = DB::table('lovejunk')->where('msgid', $msgId)->first();
        $this->assertNotNull($lj->deleted);
    }

    public function test_sends_message_with_attachment_images(): void
    {
        Http::fake([
            '*/freegle/drafts*' => Http::response(json_encode(['body' => ['draftId' => 'lj-draft-att']]), 200),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);

        $itemId = DB::table('items')->insertGetId(['name' => 'Table', 'popularity' => 1, 'updated' => now()]);
        DB::table('messages_items')->insert(['msgid' => $msgId, 'itemid' => $itemId]);

        DB::table('messages_attachments')->insert([
            'msgid' => $msgId,
            'externaluid' => 'freegletusd-abc123',
        ]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(1, $result['sent']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['images'][0]['url']) && str_contains($data['images'][0]['url'], 'abc123');
        });
    }

    public function test_extracts_item_from_subject_when_no_messages_items(): void
    {
        Http::fake([
            '*/freegle/drafts*' => Http::response(json_encode(['body' => ['draftId' => 'lj-draft-subj']]), 200),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId, [
            'subject' => 'OFFER: Vintage lamp (Shoreditch)',
        ]);

        $service = new LoveJunkService();
        $result = $service->sync();

        $this->assertEquals(1, $result['sent']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['title']) && trim($data['title']) === 'Vintage lamp';
        });
    }

    /**
     * 409 Conflict from LoveJunk's drafts endpoint is an idempotency signal
     * (LoveJunk already has the draft). It should log at WARNING level so it
     * does not escalate to Sentry, but the result must still be recorded as
     * unsuccessful so subsequent runs can retry/clean up.
     */
    public function test_409_conflict_logs_at_warning_not_error(): void
    {
        Http::fake([
            '*/freegle/drafts*' => Http::response('{"error":"already exists"}', 409),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);
        $itemId = DB::table('items')->insertGetId(['name' => 'Sofa', 'popularity' => 1, 'updated' => now()]);
        DB::table('messages_items')->insert(['msgid' => $msgId, 'itemid' => $itemId]);

        \Illuminate\Support\Facades\Log::spy();

        $service = new LoveJunkService();
        $service->sync();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context = []) =>
                str_contains($message, "LoveJunk: failed to send message $msgId")
                && ($context['status'] ?? null) === 409
            );
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');

        // Unsuccessful flag persisted.
        $lj = DB::table('lovejunk')->where('msgid', $msgId)->first();
        $this->assertNotNull($lj);
        $this->assertEquals(0, $lj->success);
    }

    /**
     * Non-409 errors from LoveJunk must still log at ERROR level — those are
     * real failures that should escalate to Sentry.
     */
    public function test_500_failure_still_logs_at_error(): void
    {
        Http::fake([
            '*/freegle/drafts*' => Http::response('{"error":"boom"}', 500),
        ]);

        $userId = $this->createUser();
        $groupId = $this->createGroup();
        $locationId = $this->createLocation();
        $msgId = $this->createMessage($userId, $groupId, $locationId);
        $itemId = DB::table('items')->insertGetId(['name' => 'Sofa', 'popularity' => 1, 'updated' => now()]);
        DB::table('messages_items')->insert(['msgid' => $msgId, 'itemid' => $itemId]);

        \Illuminate\Support\Facades\Log::spy();

        $service = new LoveJunkService();
        $service->sync();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn ($message, $context = []) =>
                str_contains($message, "LoveJunk: failed to send message $msgId")
                && ($context['status'] ?? null) === 500
            );
    }
}
