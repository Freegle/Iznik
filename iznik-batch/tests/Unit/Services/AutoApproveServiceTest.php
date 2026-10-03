<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Services\AutoApproveService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoApproveServiceTest extends TestCase
{
    use \Tests\Support\SeedsReachCells;

    protected AutoApproveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoApproveService();
    }

    public function test_stats_structure(): void
    {
        $stats = $this->service->process();

        $this->assertArrayHasKey('approved', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertArrayHasKey('errors', $stats);
    }

    public function test_approves_message_pending_over_48_hours(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        $updated = DB::table('messages')->where('id', $message->id)->first();
        $this->assertEquals(Message::COLLECTION_APPROVED, $updated->collection);
        $this->assertNull($updated->approvedby);

        // Auto-approve logs only Autoapproved — not the generic Approved entry approve()
        // would also write in a moderator-driven approval.
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Approved',
        ]);
        $this->assertDatabaseHas('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_does_not_auto_approve_a_vague_item_leaving_it_pending(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'type' => 'Wanted',
            'subject' => 'WANTED: Anything (TestLocation)',
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        $updated = DB::table('messages')->where('id', $message->id)->first();
        $this->assertEquals(
            Message::COLLECTION_PENDING,
            $updated->collection,
            'a vague-item post must stay Pending for moderator review, not auto-approve'
        );
    }

    public function test_does_not_auto_approve_message_with_spam_collection(): void
    {
        // A message classified Spam is never a Pending candidate: there is one collection
        // per message now, so process()'s own WHERE collection = Pending already excludes
        // it outright — it must never be touched, whatever its age.
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_SPAM,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        $updated = DB::table('messages')->where('id', $message->id)->first();
        $this->assertEquals(
            Message::COLLECTION_SPAM,
            $updated->collection,
            'a Spam message must never be auto-approved'
        );
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_does_not_auto_approve_an_already_taken_message(): void
    {
        // process()'s own candidate query excludes anything with a Taken/Received outcome
        // directly — approving it would re-list a gone item and fire a "newly reached" mail.
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id, 'outcome' => 'Taken', 'timestamp' => now(),
        ]);

        $this->service->process();

        $updated = DB::table('messages')->where('id', $message->id)->first();
        $this->assertEquals(
            Message::COLLECTION_PENDING,
            $updated->collection,
            'a message already Taken must not be auto-approved'
        );
    }

    public function test_auto_approve_mails_newly_reached_members_of_a_done_rippling_post(): void
    {
        // A rippling post auto-approved AFTER its reach has finished expanding ('done') must
        // still mail the now-reachable immediate members (the ExpandService tick loop won't
        // revisit a 'done' post) — closing the post-'done' approval gap.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser(['added' => now()->subHours(72)]);
        $member = $this->createTestUser([
            'added' => now()->subHours(72),
            'emailfrequency' => -1, // immediate — required to be a mailNewlyReachedForPost candidate
            'settings' => ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]],
        ]);

        $message = $this->createTestMessage($poster, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);
        // Reach (status 'done') covering the member's location; the stored label is the
        // record and the faked routing server admits the point.
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'done', NOW(), NOW())",
            [$message->id, $this->reachCellsFor('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))'), 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );
        DB::table('rippling_reach')->where('msgid', $message->id)->update(['reach_labels' => 'label-bytes']);
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (!str_contains($request->url(), 'reach-arrival')) {
                return null;
            }
            $results = array_map(fn ($pt) => ['arrival' => 100, 'in' => true], $request['points'] ?? []);

            return \Illuminate\Support\Facades\Http::response(['results' => $results]);
        });

        $this->service->process();

        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists(),
            'auto-approve mails a now-reachable immediate member of a done-reach rippling post'
        );
    }

    /**
     * The newly-reached reach mail must never go out for a post that has already been collected -
     * notifying people about a gone item. mailNewlyReachedForPost guards on the outcome directly.
     */
    public function test_mail_newly_reached_skips_taken_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser(['added' => now()->subHours(72)]);
        $member = $this->createTestUser([
            'added' => now()->subHours(72),
            'emailfrequency' => -1,
            'settings' => ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]],
        ]);

        $message = $this->createTestMessage($poster, [
            'collection' => Message::COLLECTION_APPROVED,
            'arrival' => now()->subHours(1),
        ]);
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'done', NOW(), NOW())",
            [$message->id, $this->reachCellsFor('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))'), 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );
        // The item has been collected before the newly-reached mail runs.
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id, 'outcome' => 'Taken', 'timestamp' => now(),
        ]);

        $sent = app(\App\Services\UnifiedDigestService::class)->mailNewlyReachedForPost((int) $message->id);

        $this->assertEquals(0, $sent, 'a taken post is not mailed to newly-reached members');
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->exists(),
            'no reach notification recorded for a taken post'
        );
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $stats = $this->service->process(dryRun: true);

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'collection' => Message::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_skips_message_not_pending_long_enough(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(24),
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'collection' => Message::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_message_with_recent_logs(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        // A recent hold/unhold log (within 48 hours) defers the fallback — V1 parity.
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(1),
            'type' => 'Message',
            'subtype' => 'Hold',
            'msgid' => $message->id,
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'collection' => Message::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_new_account_under_48_hours(): void
    {
        // Account created only 24 hours ago — under ACCOUNT_HOURS. Replaces V1's per-group
        // membership-age gate: there is no receiving group to be a member of long enough on,
        // so the account's own creation time (users.added) is the gate.
        $user = $this->createTestUser(['added' => now()->subHours(24)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'collection' => Message::COLLECTION_PENDING,
        ]);
    }

    public function test_records_ham_for_spam_message(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'spamtype' => 'SpamAssassin',
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_skips_held_message(): void
    {
        // messages.heldby is the only hold column now — V1's per-group messages_groups.heldby
        // is gone with the group dimension.
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'heldby' => $user->id,
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'collection' => Message::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_soft_deleted_message(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            // The poster soft-deleted their own message shortly after posting.
            'deleted' => now()->subHours(47),
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        // A soft-deleted message must never be auto-approved — moderators don't see it in
        // their queue, so an Autoapproved log would appear with no visible review.
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'collection' => Message::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_whitelists_subject_for_subject_used_for_different_groups(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            // Historical bracket-prefixed subject shape; SubjectUsedForDifferentGroups is a
            // stored spamtype ENUM value the service still checks verbatim, not a live
            // per-group concept.
            'subject' => '[TestGroup] OFFER: Sofa (Southend)',
            'spamtype' => 'SubjectUsedForDifferentGroups',
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        $this->assertDatabaseHas('spam_whitelist_subjects', [
            'subject' => AutoApproveService::getPrunedSubject('[TestGroup] OFFER: Sofa (Southend)'),
            'comment' => 'Marked as not spam',
        ]);
        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_does_not_whitelist_subject_for_other_spamtypes(): void
    {
        $user = $this->createTestUser(['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, [
            'subject' => 'OFFER: Sofa',
            'spamtype' => 'SpamAssassin',
            'collection' => Message::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        $this->service->process();

        $this->assertDatabaseMissing('spam_whitelist_subjects', [
            'subject' => AutoApproveService::getPrunedSubject('OFFER: Sofa'),
        ]);
        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_get_pruned_subject(): void
    {
        $this->assertEquals(
            'OFFER: Sofa',
            trim(AutoApproveService::getPrunedSubject('OFFER: Sofa (Southend)'))
        );
        $this->assertEquals(
            'OFFER: Sofa',
            trim(AutoApproveService::getPrunedSubject('[TestGroup] OFFER: Sofa'))
        );
        $this->assertEquals(
            'OFFER: Sofa',
            trim(AutoApproveService::getPrunedSubject('[TestGroup] OFFER: Sofa (Southend)'))
        );
        $this->assertEquals(
            'OFFER: Sofa',
            trim(AutoApproveService::getPrunedSubject('OFFER: Sofa'))
        );
    }

    public function test_constants(): void
    {
        $this->assertEquals(48, AutoApproveService::PENDING_HOURS);
        $this->assertEquals(48, AutoApproveService::ACCOUNT_HOURS);
    }
}
