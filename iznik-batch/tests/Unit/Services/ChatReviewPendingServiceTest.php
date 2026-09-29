<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatReviewPendingMail;
use App\Mail\Chat\ChatReviewSummaryMail;
use App\Models\Group;
use App\Models\Membership;
use App\Services\ChatReviewPendingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Unit coverage for {@see ChatReviewPendingService} beyond the smoke-level
 * assertions in {@see \Tests\Feature\Chat\ReviewPendingCommandTest}: the
 * per-group eligibility filters and mod-email resolution inside
 * notifyMods()/getModEmails(), which the command test never exercises.
 *
 * The notify query groups pending messages by the RECIPIENT's (the chat
 * partner who is not the message author) group memberships, then notifies
 * that group's own mods - so every fixture below builds a chat message
 * whose author is a plain user and whose recipient carries the membership
 * under test.
 */
class ChatReviewPendingServiceTest extends TestCase
{
    private ChatReviewPendingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatReviewPendingService();
    }

    private function pendingMessage(int $hoursOld = 72): array
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $message = $this->createTestChatMessage($room, $sender, [
            'date' => now()->subHours($hoursOld)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ]);

        return [$sender, $recipient, $message];
    }

    public function test_group_excluded_when_not_freegle_type(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup(['type' => Group::TYPE_OTHER]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MODERATOR]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_group_excluded_when_unpublished(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup(['publish' => 0]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MODERATOR]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_group_skipped_when_recipient_is_plain_member_with_no_mods(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_message_from_deleted_sender_excluded_from_notification(): void
    {
        Mail::fake();

        $sender = $this->createTestUser(['deleted' => now()]);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subHours(72)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ]);

        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MODERATOR]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_held_message_excluded_from_notification(): void
    {
        Mail::fake();

        [$sender, $recipient, $message] = $this->pendingMessage();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MODERATOR]);

        DB::table('chat_messages_held')->insert([
            'msgid' => $message->id,
            'userid' => $sender->id,
            'timestamp' => now(),
        ]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_message_pending_less_than_notify_window_is_not_notified(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage(hoursOld: 10);
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MODERATOR]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_deleted_mod_excluded_from_mod_emails(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup();
        $deletedMod = $this->createTestUser(['deleted' => now()]);
        $this->createMembership($deletedMod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_mod_whose_only_email_is_system_address_excluded(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['email_preferred' => ChatReviewPendingService::SYSTEM_MOD_EMAIL]);
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_pending_collection_membership_does_not_count_as_mod(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup();
        // A Moderator role membership that is not yet Approved must not count.
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_PENDING,
        ]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    /**
     * @dataProvider modRoleProvider
     */
    public function test_owner_and_moderator_roles_both_notified(string $role): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => $role]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertSame(1, $result['groups_notified']);
        Mail::assertSent(ChatReviewPendingMail::class, function (ChatReviewPendingMail $mail) use ($mod) {
            return $mail->recipientEmail === $mod->email_preferred;
        });
    }

    public static function modRoleProvider(): array
    {
        return [
            'moderator' => [Membership::ROLE_MODERATOR],
            'owner' => [Membership::ROLE_OWNER],
        ];
    }

    public function test_multiple_groups_notified_aggregate_summary_and_total(): void
    {
        Mail::fake();

        // Group A: one pending message → singular "message" wording.
        [$senderA, $recipientA] = $this->pendingMessage();
        $groupA = $this->createTestGroup();
        $modA = $this->createTestUser();
        $this->createMembership($modA, $groupA, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($recipientA, $groupA, ['role' => Membership::ROLE_MEMBER]);

        // Group B: two pending messages from two different senders to the
        // same recipient → plural "messages" wording.
        $recipientB = $this->createTestUser();
        $senderB1 = $this->createTestUser();
        $senderB2 = $this->createTestUser();
        $roomB1 = $this->createTestChatRoom($senderB1, $recipientB);
        $roomB2 = $this->createTestChatRoom($senderB2, $recipientB);
        $this->createTestChatMessage($roomB1, $senderB1, [
            'date' => now()->subHours(72)->toDateTimeString(),
            'reviewrequired' => 1, 'reviewedby' => null, 'reviewrejected' => 0,
        ]);
        $this->createTestChatMessage($roomB2, $senderB2, [
            'date' => now()->subHours(72)->toDateTimeString(),
            'reviewrequired' => 1, 'reviewedby' => null, 'reviewrejected' => 0,
        ]);
        $groupB = $this->createTestGroup();
        $modB = $this->createTestUser();
        $this->createMembership($modB, $groupB, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($recipientB, $groupB, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertSame(2, $result['groups_notified']);
        Mail::assertSentCount(3); // 2 per-group notices + 1 summary.

        Mail::assertSent(ChatReviewSummaryMail::class, function (ChatReviewSummaryMail $mail) use ($groupA, $groupB) {
            return $mail->total === 3
                && str_contains($mail->summary, $groupA->nameshort . ' — 1 message' . "\r\n")
                && str_contains($mail->summary, $groupB->nameshort . ' — 2 messages' . "\r\n");
        });
    }

    public function test_dry_run_counts_but_sends_no_mail(): void
    {
        Mail::fake();

        [$sender, $recipient] = $this->pendingMessage();
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview(dryRun: true);

        $this->assertSame(1, $result['groups_notified']);
        Mail::assertNothingSent();
    }

    public function test_auto_reject_stamps_reviewedby_with_system_user_when_present(): void
    {
        Mail::fake();

        $systemUser = $this->createTestUser(['email_preferred' => ChatReviewPendingService::SYSTEM_MOD_EMAIL]);

        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $message = $this->createTestChatMessage($room, $sender, [
            'date' => now()->subDays(8)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ]);

        $result = $this->service->processReview();

        $this->assertSame(1, $result['auto_rejected']);
        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'reviewrejected' => 1,
            'reviewedby' => $systemUser->id,
        ]);
    }

    public function test_auto_reject_leaves_reviewedby_null_when_no_system_user_exists(): void
    {
        Mail::fake();

        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $message = $this->createTestChatMessage($room, $sender, [
            'date' => now()->subDays(8)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ]);

        $result = $this->service->processReview();

        $this->assertSame(1, $result['auto_rejected']);
        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'reviewrejected' => 1,
            'reviewedby' => null,
        ]);
    }
}
