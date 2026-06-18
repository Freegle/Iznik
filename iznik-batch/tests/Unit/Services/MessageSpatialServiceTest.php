<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\MessageSpatialService;
use App\Services\SpatialAdminService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageSpatialServiceTest extends TestCase
{
    protected MessageSpatialService $service;
    protected SpatialAdminService $spatialAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a no-op spatial admin — spatial server is not running in tests.
        $this->spatialAdmin = $this->createMock(SpatialAdminService::class);
        $this->service = new MessageSpatialService($this->spatialAdmin);
        DB::statement('DELETE FROM messages_spatial');
    }

    public function test_adds_new_approved_message_to_spatial_index(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: sofa (London)',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        $result = $this->service->updateSpatialIndex();

        $this->assertEquals(1, DB::table('messages_spatial')->where('msgid', $message->id)->count());
        $this->assertGreaterThanOrEqual(1, $result);
    }

    public function test_removes_withdrawn_message_from_spatial_index(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: chair (London)',
            'textbody' => 'A chair.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        // Put it in the spatial index.
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        // Mark as withdrawn.
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_WITHDRAWN,
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $message->id)->count());
    }

    public function test_removes_deleted_message_from_spatial_index(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lamp (London)',
            'textbody' => 'A lamp.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        // Mark message as deleted.
        DB::table('messages')->where('id', $message->id)->update(['deleted' => now()]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $message->id)->count());
    }

    public function test_removes_old_messages_from_spatial_index(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: table (London)',
            'textbody' => 'A table.',
            'source' => 'Platform',
            'date' => now()->subDays(32),
            'arrival' => now()->subDays(32),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(32),
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(32)]
        );

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $message->id)->count());
    }

    public function test_marks_taken_message_as_successful(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: book (London)',
            'textbody' => 'A book.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival, successful) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?, 0)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_TAKEN,
        ]);

        $this->service->updateSpatialIndex();

        $row = DB::table('messages_spatial')->where('msgid', $message->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals(1, $row->successful);
    }

    public function test_notifies_spatial_admin_when_withdrawn_message_hard_deleted(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: kettle (London)',
            'textbody' => 'A kettle.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_WITHDRAWN,
        ]);

        $this->spatialAdmin
            ->expects($this->once())
            ->method('removeItems')
            ->with('messages', $this->containsEqual($message->id));

        $this->service->updateSpatialIndex();
    }

    public function test_notifies_spatial_admin_when_deleted_message_removed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: mug (London)',
            'textbody' => 'A mug.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        DB::table('messages')->where('id', $message->id)->update(['deleted' => now()]);

        $this->spatialAdmin
            ->expects($this->once())
            ->method('removeItems')
            ->with('messages', $this->containsEqual($message->id));

        $this->service->updateSpatialIndex();
    }

    public function test_crosspost_gets_one_spatial_row_per_group(): void
    {
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: bookcase (London)',
            'textbody' => 'A bookcase.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupA->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        // First run inserts one row per group.
        $this->service->updateSpatialIndex();

        $groupids = DB::table('messages_spatial')->where('msgid', $message->id)->pluck('groupid')->all();
        sort($groupids);
        $expected = [$groupA->id, $groupB->id];
        sort($expected);
        $this->assertEquals($expected, $groupids, 'a cross-post should be indexed once per group');

        // Second run must not flip-flop the groupid or change the row set (the old
        // single-row schema re-wrote the stored groupid on every reconciler run).
        $upserted = $this->service->updateSpatialIndex()['upserted_recent'];
        $groupidsAfter = DB::table('messages_spatial')->where('msgid', $message->id)->pluck('groupid')->all();
        sort($groupidsAfter);
        $this->assertEquals($expected, $groupidsAfter, 'the per-group rows must be stable across runs');
        $this->assertEquals(0, $this->countUpsertsForMessage($message->id), 'no per-group row should need re-upserting on the second run');
        $this->assertGreaterThanOrEqual(0, $upserted);
    }

    public function test_per_group_non_approval_removes_only_that_group(): void
    {
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: desk (London)',
            'textbody' => 'A desk.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        // Still approved on A; no longer approved on B (e.g. held/pending there).
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupA->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subDays(5),
        ]);

        // Seed spatial rows for both groups (as if both had been approved before).
        foreach ([$groupA->id, $groupB->id] as $gid) {
            DB::statement(
                "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
                [$message->id, $gid, Message::TYPE_OFFER, now()->subDays(5)]
            );
        }

        // The message is still live on group A, so the external spatial server must
        // NOT be told to drop the msgid.
        $this->spatialAdmin->expects($this->never())->method('removeItems');

        $this->service->updateSpatialIndex();

        $groupids = DB::table('messages_spatial')->where('msgid', $message->id)->pluck('groupid')->all();
        $this->assertEquals([$groupA->id], array_values($groupids), 'only the still-approved group keeps its spatial row');
    }

    private function countUpsertsForMessage(int $msgid): int
    {
        // A stable per-group set means upsertRecentMessages finds nothing to change
        // for this message: every (msgid, groupid) row already matches.
        $cutoff = date('Y-m-d', strtotime('Midnight 31 days ago'));

        return DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->leftJoin('messages_spatial', function ($join) {
                $join->on('messages_spatial.msgid', '=', 'messages_groups.msgid')
                    ->on('messages_spatial.groupid', '=', 'messages_groups.groupid');
            })
            ->where('messages.id', $msgid)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.arrival', '>=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('messages_spatial.msgid')
                    ->orWhereRaw('ST_X(messages_spatial.point) != messages.lng')
                    ->orWhereRaw('ST_Y(messages_spatial.point) != messages.lat')
                    ->orWhereRaw('messages_groups.arrival != messages_spatial.arrival');
            })
            ->count();
    }
}
