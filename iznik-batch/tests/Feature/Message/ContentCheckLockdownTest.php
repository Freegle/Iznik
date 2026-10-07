<?php

namespace Tests\Feature\Message;

use App\Models\MessageGroup;
use App\Services\ContentCheckService;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownHoldsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ContentCheckService::processUnprocessed under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.11). Ordinary (no lockdown)
 * promotion behaviour is covered by ContentCheckTest; this file is only the lockdown branch.
 */
class ContentCheckLockdownTest extends TestCase
{
    private ContentCheckService $service;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_holds')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        // Mark any pre-existing unprocessed rows so processUnprocessed() only sees
        // rows inserted within this test, same reasoning as ContentCheckTest::setUp.
        DB::table('messages_groups')
            ->whereNull('contentcheck_checked_at')
            ->update(['contentcheck_checked_at' => now()]);
        DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->whereColumn('m.editedat', '>', 'mg.contentcheck_checked_at')
            ->update(['mg.contentcheck_checked_at' => now()]);

        $this->lockdown = new LockdownService();
        $this->service = new ContentCheckService(null, null, $this->lockdown);
    }

    /**
     * @param array $groupAttrs Extra createTestGroup() attributes - e.g. ['settings' =>
     *        ['moderated' => true]] for a moderated group. Default is an unmoderated group.
     * @param array $membershipAttrs Extra createMembership() attributes. Default is an
     *        unmoderated member (ourPostingStatus DEFAULT).
     */
    private function makePendingPost(array $groupAttrs = [], array $membershipAttrs = ['ourPostingStatus' => 'DEFAULT']): array
    {
        $group = $this->createTestGroup($groupAttrs);
        $user = $this->createTestUser();
        $this->createMembership($user, $group, $membershipAttrs);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type' => 'Offer',
            'subject' => 'OFFER: Solid oak table (SW1A)',
            'textbody' => 'Beautiful table. Collection only.',
            'message' => 'Beautiful table. Collection only.',
            'arrival' => now()->subHours(2),
            'date' => now()->subHours(2),
            'source' => 'Platform',
            'lat' => 51.50,
            'lng' => -0.13,
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid' => $msgid,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(2),
            'deleted' => 0,
        ]);

        return [$msgid, $group->id, $user->id];
    }

    private function groupRow(int $msgid, int $groupid): ?object
    {
        return DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupid)->first();
    }

    // --- Guard: no promotion while posts held ---

    public function test_clean_post_not_promoted_while_posts_held(): void
    {
        $this->lockdown->press(null, 'test');

        [$msgid, $groupid] = $this->makePendingPost();

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(0, $stats['approved']);
        $this->assertEquals(1, $stats['kept_pending']);

        $row = $this->groupRow($msgid, $groupid);
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $row->collection, 'held posts stay Pending, not promoted');
        $this->assertNotNull($row->contentcheck_checked_at, 'checking is not acting - it is still checked');
    }

    public function test_post_with_an_open_hold_is_still_checked_while_held(): void
    {
        $this->lockdown->press(null, 'test');
        [$msgid, $groupid, $userid] = $this->makePendingPost();
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_POST,
            'refid' => $msgid,
            'userid' => $userid,
            'created' => now(),
        ]);

        $this->service->processUnprocessed();

        $row = $this->groupRow($msgid, $groupid);
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $row->collection);
        $this->assertNotNull($row->contentcheck_checked_at, 'moderators only see a pending post once it has been checked');
        $this->assertNull(DB::table('lockdown_holds')->where('refid', $msgid)->value('outcome'), 'still held');
    }

    // --- Lifting: everything held goes through the normal decision, straight away ---

    public function test_held_post_promoted_when_posts_lifted(): void
    {
        $this->lockdown->press(null, 'test');
        [$msgid, $groupid, $userid] = $this->makePendingPost();

        $holdId = DB::table('lockdown_holds')->insertGetId([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_POST,
            'refid' => $msgid,
            'userid' => $userid,
            'created' => now(),
        ]);

        $this->lockdown->setSurfaces(['posts' => false], null);

        $before = now();
        $stats = $this->service->processUnprocessed();

        $row = $this->groupRow($msgid, $groupid);
        $this->assertSame(MessageGroup::COLLECTION_APPROVED, $row->collection);
        $this->assertTrue(\Carbon\Carbon::parse($row->arrival)->greaterThanOrEqualTo($before->subSecond()), 'arrival is reset to NOW on release');

        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertSame('released', $hold->outcome);
        $this->assertNotNull($hold->releasedat);
    }

    public function test_held_post_in_moderated_group_stays_pending_after_lift(): void
    {
        $this->lockdown->press(null, 'test');
        // The member themselves is unmoderated - only the group is. Lifting re-decides
        // through the normal path, so a moderated group still holds the post.
        [$msgid, $groupid, $userid] = $this->makePendingPost(['settings' => ['moderated' => true]]);

        $holdId = DB::table('lockdown_holds')->insertGetId([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_POST,
            'refid' => $msgid,
            'userid' => $userid,
            'created' => now(),
        ]);

        $this->lockdown->setSurfaces(['posts' => false], null);

        $this->service->processUnprocessed();

        $row = $this->groupRow($msgid, $groupid);
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $row->collection, 'the group is moderated, so lifting does not promote it directly');
        $this->assertNotNull($row->contentcheck_checked_at, 'admitting it still runs the real check, not a bypass');

        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertSame('review', $hold->outcome, 'resolved by admitting it, even though the normal decision kept it pending');
        $this->assertNotNull($hold->releasedat);
    }

    public function test_a_hold_already_claimed_by_another_release_is_left_alone(): void
    {
        $this->lockdown->press(null, 'test');
        [$msgid, $groupid, $userid] = $this->makePendingPost();
        $holdId = DB::table('lockdown_holds')->insertGetId([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_POST,
            'refid' => $msgid,
            'userid' => $userid,
            'outcome' => 'releasing',
            'created' => now(),
        ]);
        $this->lockdown->setSurfaces(['posts' => false], null);

        $this->service->releaseHeldPosts();

        $this->assertSame('releasing', DB::table('lockdown_holds')->where('id', $holdId)->value('outcome'),
            'the other release finishes it; this one does not admit it twice');
    }

    public function test_withdrawn_held_post_is_closed_as_gone_on_lift(): void
    {
        $this->lockdown->press(null, 'test');
        [$msgid, $groupid, $userid] = $this->makePendingPost();

        $holdId = DB::table('lockdown_holds')->insertGetId([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_POST,
            'refid' => $msgid,
            'userid' => $userid,
            'created' => now(),
        ]);
        DB::table('messages_groups')->where('msgid', $msgid)->update(['deleted' => 1]);

        $this->lockdown->setSurfaces(['posts' => false], null);
        $this->service->processUnprocessed();

        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertSame('gone', $hold->outcome, 'resolved, so the release does not pick it up again');
        $this->assertNotNull($hold->releasedat);
    }

    public function test_whole_backlog_released_in_one_run(): void
    {
        $this->lockdown->press(null, 'test');

        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        // A lean, shared-fixture bulk insert: one group/user/membership for every post, no
        // items/messages_items rows. checkVagueItem()/checkNotAnItem() both null-guard a
        // missing item name, so this is a genuinely clean, promotable post through the real
        // decision path, just without the overhead of createTestGroup()/createTestUser()
        // per row that the other tests here use.
        // More than one batch, so the release has to go round more than once in one run.
        $total = LockdownHoldsService::RELEASE_BATCH_SIZE + 5;
        $msgids = [];
        for ($i = 0; $i < $total; $i++) {
            $msgid = DB::table('messages')->insertGetId([
                'fromuser' => $user->id,
                'type' => 'Offer',
                'subject' => "OFFER: Pacing test item {$i} (SW1A)",
                'textbody' => 'Collection only.',
                'message' => 'Collection only.',
                'arrival' => now()->subHours(2),
                'date' => now()->subHours(2),
                'source' => 'Platform',
                'lat' => 51.50,
                'lng' => -0.13,
            ]);

            DB::table('messages_groups')->insert([
                'msgid' => $msgid,
                'groupid' => $group->id,
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(2),
                'deleted' => 0,
            ]);

            DB::table('lockdown_holds')->insert([
                'lockdownid' => $this->lockdown->incidentId(),
                'kind' => LockdownHoldsService::KIND_POST,
                'refid' => $msgid,
                'userid' => $user->id,
                'created' => now(),
            ]);

            $msgids[] = $msgid;
        }

        $this->lockdown->setSurfaces(['posts' => false], null);

        $this->service->processUnprocessed();

        $resolved = DB::table('lockdown_holds')->where('kind', LockdownHoldsService::KIND_POST)->whereNotNull('outcome')->count();
        $stillOpen = DB::table('lockdown_holds')->where('kind', LockdownHoldsService::KIND_POST)->whereNull('outcome')->count();

        $this->assertEquals($total, $resolved, 'one run admits every held post, not a few hundred at a time');
        $this->assertEquals(0, $stillOpen);

        $approvedCount = DB::table('messages_groups')->whereIn('msgid', $msgids)->where('collection', MessageGroup::COLLECTION_APPROVED)->count();
        $this->assertEquals($total, $approvedCount, 'every post in this clean backlog is promoted');
    }

    // --- Mid-run press (section 11.6 point 2) ---

    public function test_second_pending_post_stops_promoting_once_pressed_mid_run(): void
    {
        [$msgid1, $groupid1] = $this->makePendingPost();
        [$msgid2, $groupid2] = $this->makePendingPost();

        // Presses the lockdown as a side effect of the first row's held() check
        // succeeding, simulating another process pressing the switch while this run is
        // already under way. The candidates query orders by mg.msgid ascending, so the
        // post created first is checked first. Call #1 is releasePostHolds()'s own guard
        // (not held, no press yet); call #2 is the first row's per-item check.
        $lockdown = new class extends LockdownService {
            public int $calls = 0;

            public function held(string $surface): bool
            {
                $this->calls++;
                $result = parent::held($surface);
                if ($this->calls === 2) {
                    $this->press(null, 'pressed mid run');
                }

                return $result;
            }
        };
        $service = new ContentCheckService(null, null, $lockdown);

        $stats = $service->processUnprocessed();

        $this->assertEquals(1, $stats['approved'], 'the post already under way when the press landed still completes');
        $this->assertEquals(1, $stats['kept_pending'], 'the next post is held at once, not promoted');

        $row1 = $this->groupRow($msgid1, $groupid1);
        $row2 = $this->groupRow($msgid2, $groupid2);
        $this->assertSame(MessageGroup::COLLECTION_APPROVED, $row1->collection);
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $row2->collection, 'held at its next item, not waiting for the next run');
    }

    public function test_ack_is_written_for_each_candidate_post(): void
    {
        $this->lockdown->press(null, 'test');
        $this->makePendingPost();

        $this->service->processUnprocessed();

        $ack = DB::table('lockdown_acks')->where('loop', 'content-check')->first();
        $this->assertNotNull($ack, 'content-check must ack so the presser sees this loop take effect');
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }
}
