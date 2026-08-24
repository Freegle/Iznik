<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReplyAttributionBackfillService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The backfill derives the DURABLE graded-attribution evidence onto legacy rows
 * (attribution IS NULL): notified ledger -> ripple_notified, pre-arrival membership of a
 * rippled-into group -> ripple_group, home bit -> home, nothing -> unknown with the
 * post_had_rippled hard-guard bit frozen. Location bits stay NULL (not derivable
 * retrospectively), and already-derived rows are never revisited.
 */
class ReplyAttributionBackfillServiceTest extends TestCase
{
    private ReplyAttributionBackfillService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReplyAttributionBackfillService();
    }

    private function insertLegacyRow(int $msgid, int $userid, int $wasHome, string $repliedAt = null): void
    {
        DB::table('rippling_reply_attribution')->insert([
            'msgid' => $msgid,
            'userid' => $userid,
            'replied_at' => $repliedAt ?? now()->subHours(2),
            'was_home_member' => $wasHome,
        ]);
    }

    private function attributionRow(int $msgid, int $userid): object
    {
        return DB::table('rippling_reply_attribution')
            ->where('msgid', $msgid)->where('userid', $userid)->first();
    }

    public function test_backfills_durable_channels_and_hard_guard(): void
    {
        $poster = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $rippledGroup = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $originGroup);

        // The post rippled into a second group yesterday.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $rippledGroup->id,
            'collection' => 'Approved',
            'arrival' => now()->subDay(),
            'rippled_in' => 1,
        ]);

        // Home member of the origin group.
        $homeUser = $this->createTestUser();
        $this->insertLegacyRow($message->id, $homeUser->id, 1);

        // Notified via the ripple mail ledger before replying.
        $notifiedUser = $this->createTestUser();
        DB::table('rippling_reach_notified')->insert([
            'msgid' => $message->id,
            'userid' => $notifiedUser->id,
            'notified_at' => now()->subDay(),
        ]);
        $this->insertLegacyRow($message->id, $notifiedUser->id, 0);

        // Established member of the rippled-into group (added before the copy arrived).
        $groupUser = $this->createTestUser();
        $this->createMembership($groupUser, $rippledGroup, ['added' => now()->subDays(3)]);
        $this->insertLegacyRow($message->id, $groupUser->id, 0);

        // No evidence at all.
        $unknownUser = $this->createTestUser();
        $this->insertLegacyRow($message->id, $unknownUser->id, 0);

        $updated = $this->service->backfill();
        $this->assertGreaterThanOrEqual(4, $updated);
        $this->assertSame(0, $this->service->pendingCount());

        $home = $this->attributionRow($message->id, $homeUser->id);
        $this->assertSame('home', $home->attribution);

        $notified = $this->attributionRow($message->id, $notifiedUser->id);
        $this->assertSame('ripple_notified', $notified->attribution);
        $this->assertSame(1, (int) $notified->was_notified);
        $this->assertSame(1, (int) $notified->post_had_rippled);
        $this->assertNull($notified->in_origin_catchment, 'location bits are not backfillable');
        $this->assertNull($notified->in_reach, 'location bits are not backfillable');

        $group = $this->attributionRow($message->id, $groupUser->id);
        $this->assertSame('ripple_group', $group->attribution);
        $this->assertSame(1, (int) $group->was_ripple_group_member);

        $unknown = $this->attributionRow($message->id, $unknownUser->id);
        $this->assertSame('unknown', $unknown->attribution);
        // The post HAD rippled (yesterday's copy predates the reply), so the hard-guard bit
        // is 1 - the reply just carries no positive ripple evidence.
        $this->assertSame(1, (int) $unknown->post_had_rippled);
    }

    public function test_never_rippled_post_freezes_hard_guard_zero(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        $replier = $this->createTestUser();
        $this->insertLegacyRow($message->id, $replier->id, 0);

        $this->service->backfill();

        $row = $this->attributionRow($message->id, $replier->id);
        $this->assertSame('unknown', $row->attribution);
        $this->assertSame(0, (int) $row->post_had_rippled);
    }

    public function test_join_to_reply_membership_is_not_ripple_group(): void
    {
        $poster = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $rippledGroup = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $originGroup);

        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $rippledGroup->id,
            'collection' => 'Approved',
            'arrival' => now()->subDay(),
            'rippled_in' => 1,
        ]);

        // Membership added AFTER the rippled copy arrived (the join-to-reply shape): they were
        // not already there when it rippled in, so this must not count as ripple_group.
        $joiner = $this->createTestUser();
        $this->createMembership($joiner, $rippledGroup, ['added' => now()->subHours(3)]);
        $this->insertLegacyRow($message->id, $joiner->id, 0);

        $this->service->backfill();

        $row = $this->attributionRow($message->id, $joiner->id);
        $this->assertSame(0, (int) $row->was_ripple_group_member);
        $this->assertSame('unknown', $row->attribution);
    }

    /**
     * Rippling auto-joins a poster to every group their post rippled into (memberships.rippled=1).
     * A legacy row's frozen was_home_member bit was set for those members too, so the backfill has
     * to look at the membership's PROVENANCE: backed only by a ripple-created join it is
     * ripple_join (rippling's own downstream effect, not pre-existing local membership); backed by
     * an ordinary join it stays home.
     */
    public function test_home_bit_backed_only_by_a_ripple_join_becomes_ripple_join(): void
    {
        $poster = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $originGroup, ['arrival' => now()->subDays(10)]);
        DB::table('messages_groups')->where('msgid', $message->id)
            ->update(['arrival' => now()->subDays(10)]);

        // Auto-joined by an EARLIER ripple of their own post, long before this post existed.
        $rippleJoined = $this->createTestUser();
        $this->createMembership($rippleJoined, $originGroup, [
            'added' => now()->subDays(30),
            'rippled' => 1,
        ]);
        $this->insertLegacyRow($message->id, $rippleJoined->id, 1);

        // Ordinary member of the same group, equally established.
        $ordinary = $this->createTestUser();
        $this->createMembership($ordinary, $originGroup, [
            'added' => now()->subDays(30),
            'rippled' => 0,
        ]);
        $this->insertLegacyRow($message->id, $ordinary->id, 1);

        // Membership gone entirely (they left since): nothing survives to reclassify against, so
        // the frozen bit must be honoured rather than the row being demoted.
        $departed = $this->createTestUser();
        $this->insertLegacyRow($message->id, $departed->id, 1);

        $this->service->backfill();

        $joined = $this->attributionRow($message->id, $rippleJoined->id);
        $this->assertSame('ripple_join', $joined->attribution);
        $this->assertSame(1, (int) $joined->was_ripple_join);

        $home = $this->attributionRow($message->id, $ordinary->id);
        $this->assertSame('home', $home->attribution);
        $this->assertSame(0, (int) $home->was_ripple_join);

        $gone = $this->attributionRow($message->id, $departed->id);
        $this->assertSame('home', $gone->attribution, 'a decayed membership keeps the frozen bit');
    }

    /**
     * Holding BOTH kinds of membership of the post's origin groups is home: the ordinary one alone
     * would have shown them the post, so rippling gets no credit for the reply.
     */
    public function test_ordinary_membership_alongside_a_ripple_join_stays_home(): void
    {
        $poster = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $groupA);
        DB::table('messages_groups')->where('msgid', $message->id)
            ->update(['arrival' => now()->subDays(10)]);
        // Cross-posted: a second ORIGIN group (rippled_in = 0).
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => 'Approved',
            'arrival' => now()->subDays(10),
            'rippled_in' => 0,
        ]);

        $replier = $this->createTestUser();
        $this->createMembership($replier, $groupA, ['added' => now()->subDays(30), 'rippled' => 1]);
        $this->createMembership($replier, $groupB, ['added' => now()->subDays(30), 'rippled' => 0]);
        $this->insertLegacyRow($message->id, $replier->id, 1);

        $this->service->backfill();

        $row = $this->attributionRow($message->id, $replier->id);
        $this->assertSame('home', $row->attribution);
    }

    public function test_already_derived_rows_are_untouched_and_limit_respected(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        // A row the Go capture already derived - including location evidence the backfill
        // could never reconstruct. It must not be revisited.
        $captured = $this->createTestUser();
        DB::table('rippling_reply_attribution')->insert([
            'msgid' => $message->id,
            'userid' => $captured->id,
            'replied_at' => now()->subHour(),
            'was_home_member' => 0,
            'in_origin_catchment' => 0,
            'in_reach' => 1,
            'post_had_rippled' => 1,
            'attribution' => 'ripple_reach',
            'client_source' => 'browse',
        ]);

        $legacyA = $this->createTestUser();
        $legacyB = $this->createTestUser();
        $this->insertLegacyRow($message->id, $legacyA->id, 0);
        $this->insertLegacyRow($message->id, $legacyB->id, 0);

        // limit=1 processes exactly one of the two legacy rows.
        $updated = $this->service->backfill(500, 1);
        $this->assertSame(1, $updated);
        $this->assertSame(1, DB::table('rippling_reply_attribution')
            ->where('msgid', $message->id)->whereNull('attribution')->count());

        $row = $this->attributionRow($message->id, $captured->id);
        $this->assertSame('ripple_reach', $row->attribution, 'captured rows are never revisited');
        $this->assertSame('browse', $row->client_source);
    }
}
