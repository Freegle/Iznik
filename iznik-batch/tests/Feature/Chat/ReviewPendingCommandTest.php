<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\ChatReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewPendingCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createModbotUser(): User
    {
        $user = $this->createTestUser();
        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => config('freegle.mail.moderator_email'),
            'added' => now(),
        ]);
        return $user;
    }

    private function createGroup(array $attributes = []): object
    {
        $id = DB::table('groups')->insertGetId(array_merge([
            'nameshort' => 'TestGroup_' . uniqid(),
            'type' => 'Freegle',
            'publish' => 1,
            'onmap' => 1,
            'onhere' => 1,
        ], $attributes));
        return DB::table('groups')->where('id', $id)->first();
    }

    private function addMembership(int $userId, int $groupId): void
    {
        DB::table('memberships')->insertOrIgnore([
            'userid' => $userId,
            'groupid' => $groupId,
            'collection' => 'Approved',
            'role' => 'Member',
            'added' => now(),
        ]);
    }

    private function createReviewMessage(
        ChatRoom $room,
        User $sender,
        string $date,
        bool $rejected = false,
        ?int $reviewedby = null
    ): ChatMessage {
        return $this->createTestChatMessage($room, $sender, [
            'date' => $date,
            'reviewrequired' => 1,
            'reviewrejected' => $rejected ? 1 : 0,
            'reviewedby' => $reviewedby,
        ]);
    }

    // ── Basic smoke tests ────────────────────────────────────────────────────

    public function test_command_runs_with_no_data(): void
    {
        $this->artisan('chats:review-pending')
            ->assertExitCode(0);
    }

    public function test_dry_run_shows_prefix(): void
    {
        $this->artisan('chats:review-pending', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }

    // ── Auto-reject stale messages (7+ days) ────────────────────────────────

    public function test_auto_rejects_messages_older_than_7_days(): void
    {
        $modbot = $this->createModbotUser();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $staleDate = date('Y-m-d H:i:s', strtotime('8 days ago'));
        $msg = $this->createReviewMessage($room, $user1, $staleDate);

        (new ChatReviewService())->processReview(false);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $msg->id,
            'reviewedby' => $modbot->id,
            'reviewrejected' => 1,
        ]);
    }

    public function test_does_not_reject_messages_within_7_days(): void
    {
        $this->createModbotUser();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $recentDate = date('Y-m-d H:i:s', strtotime('6 days ago'));
        $msg = $this->createReviewMessage($room, $user1, $recentDate);

        (new ChatReviewService())->processReview(false);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $msg->id,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ]);
    }

    public function test_dry_run_does_not_reject_messages(): void
    {
        $this->createModbotUser();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $staleDate = date('Y-m-d H:i:s', strtotime('8 days ago'));
        $msg = $this->createReviewMessage($room, $user1, $staleDate);

        (new ChatReviewService())->processReview(true);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $msg->id,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ]);
    }

    public function test_returns_rejected_count(): void
    {
        $this->createModbotUser();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $staleDate = date('Y-m-d H:i:s', strtotime('8 days ago'));
        $this->createReviewMessage($room, $user1, $staleDate);
        $this->createReviewMessage($room, $user1, $staleDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(2, $result['rejected']);
    }

    public function test_does_not_reject_already_reviewed(): void
    {
        $modbot = $this->createModbotUser();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $staleDate = date('Y-m-d H:i:s', strtotime('8 days ago'));
        $this->createReviewMessage($room, $user1, $staleDate, false, $modbot->id);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(0, $result['rejected']);
    }

    // ── Mod notification (48+ hours) ────────────────────────────────────────

    public function test_notifies_mods_for_pending_48h_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup();
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(1, $result['notified_groups']);
        // 1 per-group email + 1 mentors summary
        Mail::assertSentCount(2);
    }

    public function test_does_not_notify_for_recent_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup();
        $this->addMembership($recipient->id, $group->id);

        $recentDate = date('Y-m-d H:i:s', strtotime('47 hours ago'));
        $this->createReviewMessage($room, $sender, $recentDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(0, $result['notified_groups']);
        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_notification_emails(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup();
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate);

        (new ChatReviewService())->processReview(true);

        Mail::assertNothingSent();
    }

    public function test_skips_non_freegle_groups(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup(['type' => 'Yahoo']);
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(0, $result['notified_groups']);
        Mail::assertNothingSent();
    }

    public function test_skips_unpublished_groups(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup(['publish' => 0]);
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(0, $result['notified_groups']);
        Mail::assertNothingSent();
    }

    public function test_excludes_already_rejected_from_pending_count(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup();
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate, true);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(0, $result['notified_groups']);
        Mail::assertNothingSent();
    }

    public function test_excludes_held_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup();
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $msg = $this->createReviewMessage($room, $sender, $oldDate);

        DB::table('chat_messages_held')->insert([
            'msgid' => $msg->id,
            'userid' => $sender->id,
            'timestamp' => now(),
        ]);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(0, $result['notified_groups']);
        Mail::assertNothingSent();
    }

    // ── Summary email to mentors ─────────────────────────────────────────────

    public function test_no_summary_when_no_pending_messages(): void
    {
        (new ChatReviewService())->processReview(false);

        Mail::assertNothingSent();
    }

    public function test_returns_correct_totals(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup();
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate);
        $this->createReviewMessage($room, $sender, $oldDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(1, $result['notified_groups']);
        $this->assertSame(2, $result['total_pending']);
    }

    public function test_uses_contactmail_when_set(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $group = $this->createGroup(['contactmail' => 'custom@example.com']);
        $this->addMembership($recipient->id, $group->id);

        $oldDate = date('Y-m-d H:i:s', strtotime('49 hours ago'));
        $this->createReviewMessage($room, $sender, $oldDate);

        $result = (new ChatReviewService())->processReview(false);

        $this->assertSame(1, $result['notified_groups']);
    }
}
