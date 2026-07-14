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

    public function test_message_deleted_between_snapshot_and_insert_is_skipped_not_fatal(): void
    {
        // upsertRecentMessages SELECTs its candidates on the read node (db2) and INSERTs on the
        // write node (db3). The split is not sticky and Galera runs with wsrep_sync_wait=0, so a
        // delete committed on db3 can still be in db2's apply queue when the SELECT reads. When
        // purge:messages (02:30 daily) hard-deletes a message, this every-5-minute reconciler can
        // read the stale still-present row and then fail the insert on the FK to messages(id)
        // (errno 1452). That one vanished message must NOT abort the run — otherwise every later
        // upsert is dropped and all four cleanup passes are skipped. It must be skipped, and a
        // valid sibling must still be indexed.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // The message that will be deleted mid-run.
        $doomed = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: vanishing (London)',
            'textbody' => 'Gone before insert.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $doomed->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(3),
        ]);

        // A valid sibling that must still be indexed despite the sibling's disappearance.
        $survivor = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: survivor (London)',
            'textbody' => 'Still here.',
            'source' => 'Platform',
            'date' => now()->subDays(3),
            'arrival' => now()->subDays(3),
            'lat' => 51.6,
            'lng' => -0.2,
        ]);
        MessageGroup::create([
            'msgid' => $survivor->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(3),
        ]);

        // Reproduce the race deterministically: the candidate SELECT is the only query carrying an
        // ST_X() term (its change-detection WHERE). DB::listen fires after a query completes, which
        // is exactly the snapshot->insert gap — hard-delete the doomed message there, before the
        // per-row inserts run. Scoped to $doomed->id so it is a harmless no-op if it ever re-fires.
        $done = false;
        DB::listen(function ($query) use ($doomed, &$done) {
            if (!$done && stripos($query->sql, 'st_x(') !== false) {
                $done = true;
                DB::table('messages_groups')->where('msgid', $doomed->id)->delete();
                DB::table('messages')->where('id', $doomed->id)->delete();
            }
        });

        // Must not throw, even though the doomed message's parent row is gone at insert time.
        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $doomed->id)->count(),
            'the deleted message is not indexed'
        );
        $this->assertEquals(
            1,
            DB::table('messages_spatial')->where('msgid', $survivor->id)->count(),
            'the valid sibling is still indexed — the loop was not aborted by the vanished message'
        );
    }

    public function test_pending_rippled_in_row_keeps_approved_origin_spatial_row(): void
    {
        // #6 regression: removeNonApprovedMessages keys on (msgid, groupid), not msgid alone.
        // A post Approved on its origin group A (with a spatial row) gets rippled Pending into
        // group B. The Pending B row must NOT cause the still-Approved origin spatial row to be
        // deleted — otherwise the post flickers out of browse on every spatial-index run.
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lamp (London)',
            'textbody' => 'A lamp.',
            'source' => 'Platform',
            'date' => now()->subDays(2),
            'arrival' => now()->subDays(2),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupA->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(2),
        ]);
        // Rippled into B, still awaiting that group's moderation.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
        ]);
        // The post's single spatial row belongs to its approved origin group A.
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $groupA->id, Message::TYPE_OFFER, now()->subDays(2)]
        );

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            1,
            DB::table('messages_spatial')->where('msgid', $message->id)->where('groupid', $groupA->id)->count(),
            'approved origin spatial row survives a Pending rippled-in row on another group'
        );
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

    public function test_crosspost_approved_on_two_groups_yields_one_spatial_row(): void
    {
        // messages_spatial has UNIQUE(msgid) — one row per message regardless of how many
        // groups it is approved on.
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

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            1,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'a message approved on two groups must produce exactly one messages_spatial row'
        );

        // A second run must not add a second row (ON DUPLICATE KEY UPDATE is idempotent).
        $this->service->updateSpatialIndex();

        $this->assertEquals(
            1,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'the single spatial row must be stable across repeated reconciler runs'
        );
    }

    public function test_spatial_row_removed_when_stored_group_moves_to_non_approved(): void
    {
        // The single spatial row stores the groupid it was written for. When that
        // messages_groups row moves to a non-Approved collection, removeNonApprovedMessages
        // (which joins on both msgid AND groupid) must remove the spatial row.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

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
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subDays(5),
        ]);

        // Seed the spatial row as if the group was previously Approved.
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'the spatial row must be removed when the stored group moves to a non-Approved collection'
        );
    }

    public function test_withdrawal_removes_single_spatial_row_and_notifies_external_once(): void
    {
        // A message approved on two groups has ONE spatial row. Withdrawing the message
        // must remove that single row and notify the external spatial server exactly once
        // (not once per messages_groups row).
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: wardrobe (London)',
            'textbody' => 'A wardrobe.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        foreach ([$groupA->id, $groupB->id] as $gid) {
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $gid,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(5),
            ]);
        }

        // One spatial row (the one-row model).
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $groupA->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_WITHDRAWN,
        ]);

        // The external spatial server is keyed by msgid, so exactly one removeItems call
        // is expected regardless of how many groups the message belonged to.
        $this->spatialAdmin
            ->expects($this->once())
            ->method('removeItems')
            ->with('messages', $this->containsEqual($message->id));

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'the single spatial row must be removed when the message is withdrawn'
        );
    }
}
