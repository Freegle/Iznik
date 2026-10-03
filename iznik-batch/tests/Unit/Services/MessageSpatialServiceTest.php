<?php

namespace Tests\Unit\Services;

use App\Models\Message;
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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        $result = $this->service->updateSpatialIndex();

        $this->assertEquals(1, DB::table('messages_spatial')->where('msgid', $message->id)->count());
        $this->assertGreaterThanOrEqual(1, $result);
    }

    public function test_removes_withdrawn_message_from_spatial_index(): void
    {
        $user = $this->createTestUser();

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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        // Put it in the spatial index.
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(5)]
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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        // Mark message as deleted.
        DB::table('messages')->where('id', $message->id)->update(['deleted' => now()]);

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $message->id)->count());
    }

    public function test_removes_old_messages_from_spatial_index(): void
    {
        $user = $this->createTestUser();

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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(32)]
        );

        $this->service->updateSpatialIndex();

        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $message->id)->count());
    }

    public function test_marks_taken_message_as_successful(): void
    {
        $user = $this->createTestUser();

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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival, successful) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, 0)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(5)]
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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(5)]
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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        DB::table('messages')->where('id', $message->id)->update(['deleted' => now()]);

        $this->spatialAdmin
            ->expects($this->once())
            ->method('removeItems')
            ->with('messages', $this->containsEqual($message->id));

        $this->service->updateSpatialIndex();
    }

    public function test_spatial_row_removed_when_message_moves_to_non_approved(): void
    {
        // removeNonApprovedMessages joins the spatial row to its message on msgid and
        // removes it once the message's own collection leaves Approved.
        $user = $this->createTestUser();

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
            'collection' => Message::COLLECTION_PENDING,
        ]);

        // Seed the spatial row as if the message was previously Approved.
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(5)]
        );

        $this->service->updateSpatialIndex();

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'the spatial row must be removed when the message moves to a non-Approved collection'
        );
    }

    /**
     * Seed an approved message present in messages_spatial with a rippling_reach row +
     * derived sandwich bounds; returns the message id. Shared by the completed/reopened
     * bounds-pruning tests (docs/developers/reference/rippling-algorithm.md section 11).
     */
    private function seedSpatialWithReachAndBounds(): int
    {
        $user = $this->createTestUser();
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
            'collection' => Message::COLLECTION_APPROVED,
        ]);
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$message->id, Message::TYPE_OFFER, now()->subDays(2)]
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
        // (docs/developers/reference/rippling-algorithm.md section 11: pruning rippling_reach itself
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

    /**
     * A fresh post carries no msgtype of its own on messages_spatial until it is indexed.
     * The upsert must record the type from messages.type, because browse's type filter,
     * the sitemap and vector search all read messages_spatial.msgtype and treat NULL as
     * neither an Offer nor a Wanted.
     */
    public function test_upsert_sets_msgtype_from_the_message(): void
    {
        $msgid = $this->eligiblePost();

        $this->service->updateSpatialIndex();

        $this->assertSame(
            Message::TYPE_OFFER,
            DB::table('messages_spatial')->where('msgid', $msgid)->value('msgtype')
        );
    }

    /**
     * A row that is already correct in every other respect but has lost its type
     * must still be picked up. Comparing types needs a null-safe test: "msgtype
     * != messages.type" is never true when the stored side is NULL, so such a row
     * was never a candidate and stayed broken for as long as it was indexed.
     */
    public function test_upsert_heals_a_spatial_row_left_with_no_msgtype(): void
    {
        $msgid = $this->eligiblePost();
        $arrival = DB::table('messages')->where('id', $msgid)->value('arrival');
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), NULL, ?)",
            [$msgid, $arrival]
        );

        $this->service->updateSpatialIndex();

        $this->assertSame(
            Message::TYPE_OFFER,
            DB::table('messages_spatial')->where('msgid', $msgid)->value('msgtype')
        );
    }

    /** Seed a live, approved, located post that fully qualifies for the index. Returns msgid. */
    private function eligiblePost(): int
    {
        $user = $this->createTestUser();

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
            'collection' => Message::COLLECTION_APPROVED,
        ]);

        return (int) $message->id;
    }

    /** Put the eligible post in the index too (some tests need the row present first), mirroring the message's own arrival exactly. */
    private function indexPost(int $msgid): void
    {
        $arrival = DB::table('messages')->where('id', $msgid)->value('arrival');
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?)",
            [$msgid, Message::TYPE_OFFER, $arrival]
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
     * The immediate-add path must consider the same CANDIDATES as the reconciler, not
     * just apply the same ordering. A message outside the 31-day window is not a
     * candidate: adding it puts the post into browse for five minutes until
     * removeOldMessages takes it straight back out.
     */
    public function test_add_approved_message_ignores_out_of_window_message(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages')->where('id', $msgid)->update(['arrival' => now()->subDays(40)]);

        $this->service->addApprovedMessage($msgid);

        $this->assertEquals(
            0,
            DB::table('messages_spatial')->where('msgid', $msgid)->count(),
            'an out-of-window message must not be added, or the reconciler immediately removes it again'
        );
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

    public function test_still_qualify_excludes_non_approved_message(): void
    {
        $msgid = $this->eligiblePost();
        DB::table('messages')->where('id', $msgid)->update(['collection' => Message::COLLECTION_PENDING]);
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
        DB::table('messages')->where('id', $msgid)
            ->update(['arrival' => now()->subDays(MessageSpatialService::RECENT_DAYS + 9)]);
        $this->assertSame([], MessageSpatialService::stillQualifyForIndex([$msgid]));
    }
}
