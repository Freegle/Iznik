<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\GroupWelcomeMail;
use App\Models\Membership;
use App\Services\MembershipsProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MembershipsProcessingServiceTest extends TestCase
{
    protected MembershipsProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MembershipsProcessingService();
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
