<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\GroupWelcomeMail;
use App\Models\Membership;
use App\Services\MembershipOriginBackfillService;
use App\Services\MembershipsProcessingService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MembershipsProcessingServiceTest extends TestCase
{
    protected MembershipsProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MembershipsProcessingService;
        Mail::fake();
    }

    // --- Basic processing ---

    public function test_marks_history_entry_as_processed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $historyId = DB::table('memberships_history')->insertGetId([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        $entry = DB::table('memberships_history')->where('id', $historyId)->first();
        $this->assertEquals(0, $entry->processingrequired);
    }

    public function test_skips_already_processed_entries(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 0,
        ]);

        $count = $this->service->processAll();
        $this->assertEquals(0, $count);
    }

    public function test_returns_count_of_processed_entries(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships_history')->insert([
            ['userid' => $user1->id, 'groupid' => $group->id, 'collection' => 'Approved', 'processingrequired' => 1],
            ['userid' => $user2->id, 'groupid' => $group->id, 'collection' => 'Approved', 'processingrequired' => 1],
        ]);

        $count = $this->service->processAll();
        $this->assertEquals(2, $count);
    }

    // --- Per-group welcome email ---

    public function test_sends_group_welcome_email_for_approved_member_with_welcome_text(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup(['welcomemail' => 'Welcome to our group! Please be nice.']);
        DB::table('groups')->where('id', $group->id)->update(['onhere' => 1]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        Mail::assertSent(GroupWelcomeMail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });
    }

    public function test_does_not_send_group_welcome_email_when_no_welcome_text(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['welcomemail' => null, 'onhere' => 1]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        Mail::assertNotSent(GroupWelcomeMail::class);
    }

    public function test_does_not_send_group_welcome_email_for_pending_membership(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup(['welcomemail' => 'Welcome!']);
        DB::table('groups')->where('id', $group->id)->update(['onhere' => 1]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Pending',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        Mail::assertNotSent(GroupWelcomeMail::class);
    }

    public function test_does_not_send_per_group_welcome_for_a_rippled_join(): void
    {
        // Rippling Out auto-joins (memberships_history.rippled = 1) get NO per-group welcome -
        // a single bundled intro email is sent by the ripple expander instead.
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup(['welcomemail' => 'Welcome to our group! Please be nice.']);
        DB::table('groups')->where('id', $group->id)->update(['onhere' => 1]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
            'rippled' => 1,
        ]);

        $this->service->processAll();

        Mail::assertNotSent(GroupWelcomeMail::class);

        // The entry is still processed (abuse detection runs), only the welcome is suppressed.
        $entry = DB::table('memberships_history')->where('userid', $user->id)->where('groupid', $group->id)->first();
        $this->assertEquals(0, (int) $entry->processingrequired, 'rippled entry still marked processed');
    }

    public function test_does_not_send_per_group_welcome_to_a_trashnothing_poster_placed_on_a_group(): void
    {
        // TN post ingestion puts a poster on the group their post's location falls in, whether
        // or not they chose it. A group they did not choose (added_origin tn_unaddressed) sends
        // them nothing; they never knowingly joined it.
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup(['welcomemail' => 'Welcome to our group! Please be nice.']);
        DB::table('groups')->where('id', $group->id)->update(['onhere' => 1]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
            'processingrequired' => 1,
            'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED,
        ]);

        $this->service->processAll();

        Mail::assertNotSent(GroupWelcomeMail::class);
        $this->assertEquals(0, (int) DB::table('memberships_history')
            ->where('userid', $user->id)->where('groupid', $group->id)->value('processingrequired'));
    }

    public function test_a_trashnothing_poster_who_chose_the_group_is_welcomed(): void
    {
        // added_origin partner, as TN's own partner add: an ordinary join, welcomed as one.
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup(['welcomemail' => 'Welcome to our group! Please be nice.']);
        DB::table('groups')->where('id', $group->id)->update(['onhere' => 1]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
            'processingrequired' => 1,
            'added_origin' => Membership::ADDED_ORIGIN_PARTNER,
        ]);

        $this->service->processAll();

        Mail::assertSent(GroupWelcomeMail::class);
    }

    // --- Flagged mod comments ---

    public function test_flags_member_for_review_when_flagged_comment_exists(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Add a flagged comment for this user on another group.
        $otherGroup = $this->createTestGroup();
        DB::table('users_comments')->insert([
            'userid' => $user->id,
            'byuserid' => $user->id,
            'groupid' => $otherGroup->id,
            'date' => now()->subDays(10),
            'user1' => 'This user seems suspicious',
            'flag' => 1,
        ]);

        // Create the membership (needed so we can set reviewrequired)
        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        // Membership should have reviewrequestedat set.
        $membership = DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertNotNull($membership->reviewrequestedat);
    }

    public function test_does_not_flag_member_when_review_already_happened_after_comment(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Flagged comment before the last review.
        DB::table('users_comments')->insert([
            'userid' => $user->id,
            'byuserid' => $user->id,
            'groupid' => $group->id,
            'date' => now()->subDays(10),
            'user1' => 'Old comment',
            'flag' => 1,
        ]);

        // Membership was reviewed after the comment.
        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'reviewedat' => now()->subDays(5),  // reviewed AFTER the comment
        ]);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        // Should NOT have reviewrequestedat set since already reviewed.
        $membership = DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertNull($membership->reviewrequestedat ?? null);
    }

    // --- Seen on many groups (V1 Spam::checkUser parity) ---

    public function test_flags_member_seen_on_many_groups(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // 36 distinct groups joined in the last year, plus the just-added group,
        // exceeds the relaxed threshold of 35 (raised from 16 for rippling-out).
        // The just-added group is counted separately by the service.
        for ($i = 0; $i < 36; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group',
                'subtype' => 'Joined',
                'user' => $user->id,
                'groupid' => 900000 + $i,
                'timestamp' => now()->subDays(30),
            ]);
        }

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        $membership = DB::table('memberships')
            ->where('userid', $user->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($membership->reviewrequestedat);
        $this->assertEquals('Seen on many groups', $membership->reviewreason);

        $this->assertTrue(
            DB::table('logs')->where('user', $user->id)
                ->where('type', 'User')->where('subtype', 'Suspect')->exists(),
            'a User/Suspect log should be written'
        );
    }

    /**
     * A member past the threshold who joins one more group, with an existing membership on a
     * group whose moderator marked them OK $reviewedDaysAgo days ago. Returns the reviewed
     * membership and the new one, after processing.
     */
    private function joinOneMoreGroupAfterReview(int $reviewedDaysAgo): array
    {
        $user = $this->createTestUser();
        $reviewedGroup = $this->createTestGroup();
        $newGroup = $this->createTestGroup();

        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $reviewedGroup->id,
            'collection' => 'Approved',
            'reviewrequestedat' => null,
            'reviewedat' => now()->subDays($reviewedDaysAgo),
            'reviewreason' => 'Seen on many groups',
        ]);
        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $newGroup->id,
            'collection' => 'Approved',
        ]);

        for ($i = 0; $i < 36; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group',
                'subtype' => 'Joined',
                'user' => $user->id,
                'groupid' => 900000 + $i,
                'timestamp' => now()->subDays(30),
            ]);
        }

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $newGroup->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        $find = fn ($groupId) => DB::table('memberships')
            ->where('userid', $user->id)->where('groupid', $groupId)->first();

        return [$find($reviewedGroup->id), $find($newGroup->id)];
    }

    public function test_seen_on_many_groups_leaves_a_group_that_marked_the_member_ok_recently(): void
    {
        [$reviewed, $new] = $this->joinOneMoreGroupAfterReview(5);

        $this->assertNull($reviewed->reviewrequestedat,
            'a group that marked the member OK 5 days ago must not be flagged again by one more join');
        $this->assertNotNull($new->reviewrequestedat, 'the group just joined is still flagged');
        $this->assertEquals('Seen on many groups', $new->reviewreason);
    }

    public function test_seen_on_many_groups_flags_again_once_the_ok_is_older_than_the_quiet_period(): void
    {
        [$reviewed, $new] = $this->joinOneMoreGroupAfterReview(MembershipsProcessingService::REVIEW_QUIET_DAYS + 5);

        $this->assertNotNull($reviewed->reviewrequestedat,
            'an OK older than the quiet period does not stop the group being flagged again');
        $this->assertNotNull($new->reviewrequestedat);
    }

    public function test_rippled_joins_do_not_count_toward_seen_on_many_groups(): void
    {
        // A single far-reaching post can ripple the poster into dozens of groups (Group/Joined
        // text='Rippled'). Those must NOT count toward "seen on many groups", or the poster would
        // be flagged for review everywhere off one popular post.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // 40 rippling joins - well over the threshold IF they counted, but they must be excluded.
        for ($i = 0; $i < 40; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group',
                'subtype' => 'Joined',
                'user' => $user->id,
                'groupid' => 900000 + $i,
                'text' => 'Rippled',
                'timestamp' => now()->subDays(30),
            ]);
        }

        // The just-added group is itself a rippled join.
        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
            'rippled' => 1,
        ]);

        $this->service->processAll();

        $membership = DB::table('memberships')
            ->where('userid', $user->id)->where('groupid', $group->id)->first();
        $this->assertNull($membership->reviewrequestedat ?? null, 'rippled joins must not trip the spam flag');
        $this->assertFalse(
            DB::table('logs')->where('user', $user->id)
                ->where('type', 'User')->where('subtype', 'Suspect')->exists(),
            'no User/Suspect log for a poster whose joins are all rippled'
        );
    }

    public function test_trashnothing_placements_do_not_count_toward_seen_on_many_groups(): void
    {
        // TN places a post on the group its location falls in, so those joins follow where
        // the poster's posts are, not a join spree. 35 chosen joins sit exactly on the threshold:
        // counting either the TN placements or the just-added one would push it over.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
        ]);

        for ($i = 0; $i < 35; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group', 'subtype' => 'Joined', 'user' => $user->id,
                'groupid' => 910000 + $i, 'text' => MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'timestamp' => now()->subDays(30),
            ]);
        }
        for ($i = 0; $i < 40; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group', 'subtype' => 'Joined', 'user' => $user->id,
                'groupid' => 920000 + $i, 'text' => GroupPostIngestionService::JOIN_LOG_UNADDRESSED,
                'timestamp' => now()->subDays(30),
            ]);
        }

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
            'processingrequired' => 1,
            'added_origin' => Membership::ADDED_ORIGIN_TN_UNADDRESSED,
        ]);

        $this->service->processAll();

        $this->assertFalse(
            DB::table('logs')->where('user', $user->id)
                ->where('type', 'User')->where('subtype', 'Suspect')->exists(),
            'TrashNothing placements must not trip the spam flag'
        );
    }

    public function test_does_not_flag_member_on_few_groups(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // Only 5 groups — well under the threshold.
        for ($i = 0; $i < 5; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group',
                'subtype' => 'Joined',
                'user' => $user->id,
                'groupid' => 910000 + $i,
                'timestamp' => now()->subDays(30),
            ]);
        }

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        $membership = DB::table('memberships')
            ->where('userid', $user->id)->where('groupid', $group->id)->first();
        $this->assertNull($membership->reviewrequestedat ?? null);
    }

    public function test_does_not_flag_member_between_old_and_new_threshold(): void
    {
        // 20 distinct groups would have flagged under the old threshold of 16, but the relaxed
        // threshold (35, for rippling-out) must NOT flag them - this is the point of the relax.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        for ($i = 0; $i < 20; $i++) {
            DB::table('logs')->insert([
                'type' => 'Group',
                'subtype' => 'Joined',
                'user' => $user->id,
                'groupid' => 920000 + $i,
                'timestamp' => now()->subDays(30),
            ]);
        }

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll();

        $membership = DB::table('memberships')
            ->where('userid', $user->id)->where('groupid', $group->id)->first();
        $this->assertNull($membership->reviewrequestedat ?? null);
    }

    // --- Dry-run mode ---

    public function test_dry_run_does_not_mark_entries_as_processed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $historyId = DB::table('memberships_history')->insertGetId([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $count = $this->service->processAll(dryRun: true);

        $this->assertEquals(1, $count);

        $entry = DB::table('memberships_history')->where('id', $historyId)->first();
        $this->assertEquals(1, $entry->processingrequired);
    }

    public function test_dry_run_does_not_send_welcome_email(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(['onhere' => 1, 'welcomemail' => 'Welcome!']);

        DB::table('memberships_history')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'processingrequired' => 1,
        ]);

        $this->service->processAll(dryRun: true);

        Mail::assertNothingSent();
    }
}
