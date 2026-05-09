<?php

namespace Tests\Feature\Chat;

use App\Models\Membership;
use App\Services\ChatReviewPendingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewPendingCommandTest extends TestCase
{
    public function test_smoke_nothing_pending(): void
    {
        Mail::fake();

        $this->artisan('chats:review-pending')
            ->expectsOutputToContain('Auto-rejected 0 message(s)')
            ->expectsOutputToContain('Notified mods for 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_auto_rejects_stale_review_messages(): void
    {
        Mail::fake();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room  = $this->createTestChatRoom($user1, $user2);

        // Message stuck for 8 days (> AUTO_REJECT_DAYS)
        $this->createTestChatMessage($room, $user1, [
            'date'           => now()->subDays(8)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby'     => null,
            'reviewrejected' => 0,
        ]);

        $this->artisan('chats:review-pending')
            ->expectsOutputToContain('Auto-rejected 1 message(s)')
            ->assertExitCode(0);

        $rejected = DB::table('chat_messages')
            ->where('reviewrequired', 1)
            ->where('reviewrejected', 1)
            ->count();
        $this->assertEquals(1, $rejected);
    }

    public function test_does_not_reject_recent_messages(): void
    {
        Mail::fake();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room  = $this->createTestChatRoom($user1, $user2);

        // Message only 1 day old
        $this->createTestChatMessage($room, $user1, [
            'date'           => now()->subDay()->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby'     => null,
            'reviewrejected' => 0,
        ]);

        $this->artisan('chats:review-pending')
            ->expectsOutputToContain('Auto-rejected 0 message(s)')
            ->assertExitCode(0);
    }

    public function test_notifies_mods_when_messages_pending_48h(): void
    {
        Mail::fake();

        $mod    = $this->createTestUser();
        $member = $this->createTestUser();
        $group  = $this->createTestGroup();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $room = $this->createTestChatRoom($member, $mod);

        // Message pending review for 3 days
        $this->createTestChatMessage($room, $member, [
            'date'           => now()->subDays(3)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby'     => null,
            'reviewrejected' => 0,
        ]);

        $this->artisan('chats:review-pending')
            ->expectsOutputToContain('Notified mods for 1 group(s)')
            ->assertExitCode(0);

        // At least 1 email (to mod) + 1 (mentors summary)
        $this->assertGreaterThanOrEqual(1, Mail::getSentCount());
    }

    public function test_skips_already_reviewed_messages(): void
    {
        Mail::fake();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room  = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'date'           => now()->subDays(3)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby'     => $user2->id,
            'reviewrejected' => 0,
        ]);

        $this->artisan('chats:review-pending')
            ->expectsOutputToContain('Notified mods for 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_already_rejected_messages(): void
    {
        Mail::fake();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room  = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'date'           => now()->subDays(3)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby'     => null,
            'reviewrejected' => 1,
        ]);

        $this->artisan('chats:review-pending')
            ->expectsOutputToContain('Notified mods for 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_reject_or_send(): void
    {
        Mail::fake();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room  = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'date'           => now()->subDays(8)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby'     => null,
            'reviewrejected' => 0,
        ]);

        $this->artisan('chats:review-pending', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would auto-reject 1 message(s)')
            ->assertExitCode(0);

        // Should not have been updated
        $this->assertDatabaseHas('chat_messages', [
            'id'             => $msg->id,
            'reviewrejected' => 0,
            'reviewedby'     => null,
        ]);

        Mail::assertNothingSent();
    }
}
