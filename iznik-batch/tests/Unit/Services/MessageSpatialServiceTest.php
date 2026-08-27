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
    use \Tests\Support\SeedsReachCells;

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

    public function test_does_not_index_soft_deleted_membership(): void
    {
        // Rippling's "removed on origin removal" sets messages_groups.deleted=1 but leaves
        // collection=Approved and messages.deleted NULL. Such a removed copy — even with a recent
        // arrival (e.g. one autorepost wrongly bumped) — must NOT be indexed into browse.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: removed copy (London)',
            'textbody' => 'Removed on origin removal.',
            'source' => 'Platform',
            'date' => now()->subDays(2),
            'arrival' => now()->subDays(2),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(2),
            'deleted' => 1,
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'a soft-deleted (removed) membership must not be indexed'
        );
    }

    public function test_removes_spatial_row_when_membership_soft_deleted(): void
    {
        // An already-indexed row whose backing membership is later soft-deleted (deleted=1,
        // collection still Approved) must be removed — otherwise the removed copy lingers in
        // browse until removeOldMessages ages it out (up to 31 days).
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lingering (London)',
            'textbody' => 'Indexed then removed.',
            'source' => 'Platform',
            'date' => now()->subDays(2),
            'arrival' => now()->subDays(2),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(2),
            'deleted' => 1,
        ]);
        // Already in the index, pointing at this now-removed group.
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(2)]
        );

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'the spatial row is removed when its backing membership is soft-deleted'
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

    /**
     * Seed an approved message present in messages_spatial with a rippling_reach row +
     * derived sandwich bounds; returns the message id. Shared by the completed/reopened
     * bounds-pruning tests (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
     */
    private function seedSpatialWithReachAndBounds(): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: bounds pruning (London)',
            'textbody' => 'A thing.',
            'source' => 'Platform',
            'date' => now()->subDays(2),
            'arrival' => now()->subDays(2),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(2),
        ]);
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, now()->subDays(2)]
        );
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1,
                     ?,
                     ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)),
                     ?, 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))'), now()->subDays(2)]
        );
        DB::statement(
            "UPDATE rippling_reach
                SET outer_bound = ST_GeomFromText('POLYGON((-0.3 51.3,0.1 51.3,0.1 51.7,-0.3 51.7,-0.3 51.3))', 3857),
                    inner_bound = ST_GeomFromText('POLYGON((-0.18 51.42,-0.02 51.42,-0.02 51.58,-0.18 51.58,-0.18 51.42))', 3857)
              WHERE msgid = ?",
            [$message->id]
        );

        return (int) $message->id;
    }

    public function test_completed_post_degrades_reach_bounds_but_not_the_grid(): void
    {
        // A Taken/Received post leaves the browsable candidate set. Its sandwich bounds
        // are degraded (degenerate outer, no inner) so reach queries stop matching it
        // cheaply — but the stored reach grid must stay untouched: the digest's "came and
        // went" section, held replies to taken posts and un-completion all still read it
        // (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md: pruning rippling_reach itself
        // was verified UNSAFE).
        $msgid = $this->seedSpatialWithReachAndBounds();
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid,
            'outcome' => Message::OUTCOME_TAKEN,
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            1,
            (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('successful'),
            'the spatial row is flagged successful'
        );
        $row = DB::selectOne(
            'SELECT ST_GeometryType(outer_bound) AS outer_type, inner_bound IS NULL AS inner_null
               FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        );
        $this->assertNotNull($row, 'the reach row survives completion (bounds degraded, grid intact)');
        $this->assertSame('POINT', $row->outer_type, 'outer bound degrades to a degenerate point');
        $this->assertSame(1, (int) $row->inner_null, 'inner bound is cleared');
        $this->assertNotNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells'),
            'the stored reach grid is untouched'
        );
    }

    public function test_reopened_post_restores_reach_bounds_from_stored_grid(): void
    {
        // Un-completion is a real automated flow (outcome removed → successful flips back
        // to 0). The bounds must be re-derived from the stored grid (traced back to a
        // scratch geometry by the spatial server) — no routing call — or the reopened
        // post would stay invisible to the cheap path.
        $msgid = $this->seedSpatialWithReachAndBounds();

        // Completed first…
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => Message::OUTCOME_TAKEN]);
        $this->service->updateSpatialIndex();
        $this->assertSame(
            'POINT',
            DB::selectOne('SELECT ST_GeometryType(outer_bound) AS t FROM rippling_reach WHERE msgid = ?', [$msgid])->t
        );

        // …then reopened.
        DB::table('messages_outcomes')->where('msgid', $msgid)->delete();
        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('successful'),
            'the spatial row is back in the browsable set'
        );
        $check = DB::selectOne(
            'SELECT ST_GeometryType(outer_bound) AS outer_type,
                    ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS o,
                    (inner_bound IS NULL OR ST_Contains(ST_GeomFromText(?, 3857), inner_bound)) AS i
               FROM rippling_reach WHERE msgid = ?',
            ['POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', $msgid]
        );
        $this->assertNotNull($check);
        $this->assertNotSame('POINT', $check->outer_type, 'real bounds are restored on reopen');
        $this->assertSame(1, (int) $check->o, 'restored outer bound contains the reach');
        $this->assertSame(1, (int) $check->i, 'restored inner bound is NULL or inside the reach');
    }

    /** Seed a live, approved, located post that fully qualifies for the index. Returns msgid. */
    private function eligiblePost(): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: table (London)',
            'textbody' => 'A table.',
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

        return (int) $message->id;
    }

    /** Put the eligible post in the index too (some tests need the row present first), mirroring its membership exactly. */
    private function indexPost(int $msgid): void
    {
        $mg = DB::table('messages_groups')->where('msgid', $msgid)->first(['groupid', 'arrival']);
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$msgid, $mg->groupid, Message::TYPE_OFFER, $mg->arrival]
        );
    }

    /**
     * A post can carry conflicting outcome rows: the write paths clean outcomes up on
     * transition (reposting deletes them, extending a deadline deletes Expired), but a
     * few paths skip that, leaving e.g. an old Expired row next to a newer Taken one.
     * The latest row is the post's current state. Expired-then-Taken means completed,
     * so the post stays in the index marked successful — before this rule, the outcome
     * pass deleted it off the stale Expired row every run and the upsert re-added it.
     */
    public function test_latest_outcome_wins_expired_then_taken_stays_indexed(): void
    {
        $msgid = $this->eligiblePost();
        $this->indexPost($msgid);

        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_EXPIRED, 'timestamp' => now()->subHours(2),
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => now()->subHours(1),
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(1, DB::table('messages_spatial')->where('msgid', $msgid)->count());
        $this->assertEquals(1, (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('successful'));
    }

    /** The mirror case: Taken then Withdrawn. The newer Withdrawn row wins; the post leaves the index. */
    public function test_latest_outcome_wins_taken_then_withdrawn_removed(): void
    {
        $msgid = $this->eligiblePost();
        $this->indexPost($msgid);

        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => now()->subHours(2),
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_WITHDRAWN, 'timestamp' => now()->subHours(1),
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $msgid)->count());
    }

    /**
     * The add side must apply the same latest-row rule as the outcome pass, or the two
     * disagree and the post is added by one and deleted by the other every single run.
     *
     * The suite's DB is shared, so other tests' committed messages contribute to
     * upserted_recent. Measure the steady candidate count with dry runs (pure reads)
     * before and after seeding this post: the count must not grow for a post whose
     * latest outcome is negative.
     */
    public function test_upsert_does_not_readd_when_latest_outcome_withdrawn(): void
    {
        $this->service->updateSpatialIndex();
        $baseline = $this->service->updateSpatialIndex(true)['upserted_recent'];

        $msgid = $this->eligiblePost();
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => now()->subHours(2),
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_WITHDRAWN, 'timestamp' => now()->subHours(1),
        ]);

        $stats = $this->service->updateSpatialIndex();

        $this->assertSame($baseline, $stats['upserted_recent'], 'a post whose latest outcome is negative must not be (re)added');
        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $msgid)->count());
    }

    /**
     * THE production churn case: an old post revived by a repost (fresh live membership)
     * still carries stale DEAD memberships - the tombstones left when its rippled-in
     * copies were retracted. The age pass must not delete it off those: a post is only
     * over-age when NO live approved membership is within the window. Before this rule
     * ~3,000 such posts were deleted at the end of every index run and re-added by the
     * next, flickering out of browse for minutes of every cycle.
     */
    public function test_keeps_reposted_post_despite_stale_dead_tombstones(): void
    {
        $msgid = $this->eligiblePost();
        $this->indexPost($msgid);

        // Two retracted-copy tombstones from an earlier ripple, now over the window.
        $groupB = $this->createTestGroup();
        $groupC = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            ['msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(40), 'deleted' => 1, 'rippled_in' => 1],
            ['msgid' => $msgid, 'groupid' => $groupC->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(45), 'deleted' => 1, 'rippled_in' => 1],
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            1,
            DB::table('messages_spatial')->where('msgid', $msgid)->count(),
            'stale DEAD memberships must not age a freshly-reposted post out of the index'
        );
    }

    /**
     * The same rule the other way round: a dead membership must not KEEP a post in
     * either. Only live approved memberships count, on both sides of the age decision.
     */
    public function test_removes_post_whose_only_fresh_membership_is_dead(): void
    {
        $msgid = $this->eligiblePost();
        $this->indexPost($msgid);
        // The live membership ages out...
        DB::table('messages_groups')->where('msgid', $msgid)->update(['arrival' => now()->subDays(40)]);
        // ...and the only fresh membership is a dead tombstone.
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(1), 'deleted' => 1, 'rippled_in' => 1,
        ]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $msgid)->count());
    }

    /**
     * messages_spatial holds ONE row per post, and everything downstream reads its
     * groupid as the post's own community. A post rippled into other groups has
     * several qualifying memberships, and the upsert used to select EVERY one whose
     * groupid/arrival differed from the stored row, rewriting the same row to a
     * different membership each run - the recorded community and arrival ping-ponged
     * forever, ~182K row rewrites per run for a ~56K-row table. One membership must
     * represent the post: the origin (non-rippled) one, latest arrival first.
     */
    public function test_upsert_keeps_spatial_row_on_the_origin_membership(): void
    {
        $msgid = $this->eligiblePost();
        $originGroup = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');
        $this->indexPost($msgid);

        // A live rippled-in copy with a FRESHER arrival must not steal the row.
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(1), 'deleted' => 0, 'rippled_in' => 1,
        ]);

        $this->service->updateSpatialIndex();

        $originArrival = DB::table('messages_groups')
            ->where('msgid', $msgid)->where('groupid', $originGroup)->value('arrival');
        $row = DB::table('messages_spatial')->where('msgid', $msgid)->first(['groupid', 'arrival']);
        $this->assertNotNull($row);
        $this->assertSame($originGroup, (int) $row->groupid, 'the spatial row must stay on the origin membership');
        // Second precision: messages_groups returns microseconds, messages_spatial does not.
        $this->assertSame(
            substr((string) $originArrival, 0, 19),
            substr((string) $row->arrival, 0, 19),
            'the recorded arrival is the origin membership\'s too'
        );
    }

    /**
     * The other half of the ping-pong: once the row matches its post's representative
     * membership, the next run must have nothing left to rewrite. Measured as a
     * dry-run candidate delta because the suite's shared DB contributes its own rows.
     */
    public function test_upsert_converges_after_one_run(): void
    {
        $this->service->updateSpatialIndex();
        $baseline = $this->service->updateSpatialIndex(true)['upserted_recent'];

        $msgid = $this->eligiblePost();
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(1), 'deleted' => 0, 'rippled_in' => 1,
        ]);

        $this->service->updateSpatialIndex();

        $this->assertSame(
            $baseline,
            $this->service->updateSpatialIndex(true)['upserted_recent'],
            'after one run the post must no longer be an upsert candidate'
        );

        // And the row itself is stable: another real run leaves it where it is.
        $chosen = (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('groupid');
        $this->service->updateSpatialIndex();
        $this->assertSame(
            $chosen,
            (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('groupid'),
            'the recorded group must not change between runs'
        );
    }

    /** Within the same class (two rippled copies), the fresher arrival represents the post. */
    public function test_representative_prefers_fresher_arrival_within_same_class(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['arrival' => now()->subDays(40)]);
        $groupB = $this->createTestGroup();
        $groupC = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            ['msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(2), 'deleted' => 0, 'rippled_in' => 1],
            ['msgid' => $msgid, 'groupid' => $groupC->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subDays(1), 'deleted' => 0, 'rippled_in' => 1],
        ]);

        $this->service->updateSpatialIndex();

        $this->assertSame(
            (int) $groupC->id,
            (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('groupid'),
            'the fresher rippled copy wins within the rippled class'
        );
    }

    /** Exact ties (same class, same arrival) break on the lower groupid, so exactly one row wins. */
    public function test_representative_breaks_exact_ties_by_lower_groupid(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['arrival' => now()->subDays(40)]);
        $arrival = now()->subDays(1)->startOfMinute();
        $groupB = $this->createTestGroup();
        $groupC = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            ['msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => $arrival, 'deleted' => 0, 'rippled_in' => 1],
            ['msgid' => $msgid, 'groupid' => $groupC->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => $arrival, 'deleted' => 0, 'rippled_in' => 1],
        ]);

        $this->service->updateSpatialIndex();

        $this->assertSame(
            min((int) $groupB->id, (int) $groupC->id),
            (int) DB::table('messages_spatial')->where('msgid', $msgid)->value('groupid'),
            'an exact tie must resolve deterministically to the lower groupid'
        );
    }

    /**
     * The fallback when only a rippled copy keeps the post alive: it is still the
     * post's one row in browse, recorded against the rippled group.
     */
    public function test_indexes_post_kept_alive_only_by_rippled_copy(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['arrival' => now()->subDays(40)]);
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(1), 'deleted' => 0, 'rippled_in' => 1,
        ]);

        $this->service->updateSpatialIndex();

        $row = DB::table('messages_spatial')->where('msgid', $msgid)->first(['groupid']);
        $this->assertNotNull($row, 'a live rippled membership keeps the post browsable');
        $this->assertSame((int) $groupB->id, (int) $row->groupid);
    }

    /**
     * The immediate-add path must consider the same CANDIDATES as the reconciler, not
     * just apply the same ordering. A membership outside the 31-day window is not a
     * candidate: adding it puts the post into browse for five minutes until
     * removeOldMessages takes it straight back out.
     */
    public function test_add_approved_message_ignores_out_of_window_membership(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['arrival' => now()->subDays(40)]);

        $this->service->addApprovedMessage($msgid);

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $msgid)->count(),
            'an out-of-window membership must not be added, or the reconciler immediately removes it again'
        );
    }

    /**
     * When the origin membership has aged out of the window but a live rippled copy is
     * fresh - the approval that typically triggers this call - the rippled copy is the
     * representative, exactly as the reconciler would choose. Recording the stale
     * origin instead made a just-approved post sort as weeks old in browse.
     */
    public function test_add_approved_message_picks_fresh_rippled_copy_over_stale_origin(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['arrival' => now()->subDays(40)]);
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(1), 'deleted' => 0, 'rippled_in' => 1,
        ]);

        $this->service->addApprovedMessage($msgid);

        $row = DB::table('messages_spatial')->where('msgid', $msgid)->first(['groupid']);
        $this->assertNotNull($row);
        $this->assertSame(
            (int) $groupB->id,
            (int) $row->groupid,
            'the fresh rippled copy is the representative when the origin is out of the window'
        );
    }

    /** The immediate-add path must pick the same representative membership as the reconciler. */
    public function test_add_approved_message_prefers_origin_membership(): void
    {
        $msgid = $this->eligiblePost();
        $originGroup = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $msgid, 'groupid' => $groupB->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(1), 'deleted' => 0, 'rippled_in' => 1,
        ]);

        $this->service->addApprovedMessage($msgid);

        $row = DB::table('messages_spatial')->where('msgid', $msgid)->first(['groupid']);
        $this->assertNotNull($row);
        $this->assertSame($originGroup, (int) $row->groupid, 'immediate add must not record a rippled group as the post\'s own');
    }

    public function test_still_qualify_includes_live_post(): void
    {
        $msgid = $this->eligiblePost();
        $this->assertSame([$msgid], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    /** Taken/Received posts stay in the index, so they still qualify. */
    public function test_still_qualify_includes_completed_post(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => Message::OUTCOME_TAKEN]);
        $this->assertSame([$msgid], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    public function test_still_qualify_excludes_latest_withdrawn_despite_older_taken(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => now()->subHours(2),
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid, 'outcome' => Message::OUTCOME_WITHDRAWN, 'timestamp' => now()->subHours(1),
        ]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    public function test_still_qualify_excludes_deleted_message(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages')->where('id', $msgid)->update(['deleted' => now()]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    public function test_still_qualify_excludes_soft_deleted_membership(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['deleted' => 1]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    public function test_still_qualify_excludes_non_approved_membership(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)->update(['collection' => MessageGroup::COLLECTION_PENDING]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    public function test_still_qualify_excludes_deleted_user(): void
    {
        $msgid = $this->eligiblePost();
        $fromuser = DB::table('messages')->where('id', $msgid)->value('fromuser');
        DB::table('users')->where('id', $fromuser)->update(['deleted' => now()]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }

    public function test_still_qualify_excludes_aged_out_post(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages_groups')->where('msgid', $msgid)
            ->update(['arrival' => now()->subDays(MessageSpatialService::RECENT_DAYS + 9)]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }
}
