<?php

namespace Tests\Unit\Services;

use App\Services\ChatReviewPendingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Unit coverage for {@see ChatReviewPendingService}: stale review messages are
 * auto-rejected, stamped with the system user when it exists.
 */
class ChatReviewPendingServiceTest extends TestCase
{
    private ChatReviewPendingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatReviewPendingService();
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
