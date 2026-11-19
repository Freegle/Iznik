<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\ChatMessage;
use App\Models\ChatMessageTn;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Ingestion\ChatMessageIngestionService;
use App\Services\TrashNothing\Ingestion\TnChatMessage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ChatMessageIngestionService::plan(): one test per row of section 1 of
 * plans/2026-11-12-tn-api-chat-ingestion.md and per reason a record is not written.
 */
class ChatMessageIngestionServiceTest extends TestCase
{
    private ChatMessageIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatMessageIngestionService;
    }

    private function tnUser(array $attributes = []): User
    {
        return $this->createTestUser(array_merge(['tnuserid' => random_int(50_000_000, 99_999_999)], $attributes));
    }

    private function record(array $fields): TnChatMessage
    {
        return TnChatMessage::fromArray(array_merge([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'date' => '2026-11-17T10:00:00Z',
            'text' => 'Yes please, I could collect tomorrow evening.',
            'display_name' => 'Test Member',
        ], $fields));
    }

    public function test_chat_reply_writes_a_default_message_from_the_account_holding_the_tn_id(): void
    {
        $poster = $this->createTestUser();
        $member = $this->tnUser();
        $chat = $this->createTestChatRoom($poster, $member);

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply',
            'tn_user_id' => $member->tnuserid,
            'fd_chat_id' => $chat->id,
            'fd_msgid' => 12345,
        ]));

        $this->assertSame(ChatIngestPlan::RESULT_WRITE, $plan->result);
        $this->assertSame(RoutingResult::TO_USER, $plan->outcome);
        $this->assertSame($chat->id, $plan->chatId);
        $this->assertSame($member->id, $plan->senderId);
        $this->assertSame(ChatMessage::TYPE_DEFAULT, $plan->messageType);
        // As the email path: a chat reply carries no refmsgid, even when TN names the post.
        $this->assertNull($plan->refmsgid);
        $this->assertFalse($plan->reviewRequired);
        $this->assertTrue($plan->touchLastAccess);
        $this->assertSame($chat->id, $plan->trackChatId);
        $this->assertSame(['user_id' => $member->id, 'chat_id' => $chat->id], $plan->context);
        $this->assertSame('Yes please, I could collect tomorrow evening.', $plan->text);
    }

    public function test_chat_reply_follows_a_merged_room_redirect_and_reports_the_named_chat(): void
    {
        $poster = $this->createTestUser();
        $member = $this->tnUser();
        $chat = $this->createTestChatRoom($poster, $member);
        $oldId = $chat->id + 1_000_000;
        DB::table('chat_room_redirects')->insert(['old_id' => $oldId, 'new_id' => $chat->id]);

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply', 'tn_user_id' => $member->tnuserid, 'fd_chat_id' => $oldId,
        ]));

        $this->assertSame($chat->id, $plan->chatId);
        $this->assertSame($oldId, $plan->context['chat_id']);
        $this->assertSame($oldId, $plan->trackChatId);
    }

    public function test_chat_reply_to_a_missing_chat_is_dropped_with_the_email_paths_reason(): void
    {
        $member = $this->tnUser();

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply', 'tn_user_id' => $member->tnuserid, 'fd_chat_id' => 999_999_999,
        ]));

        $this->assertSame(ChatIngestPlan::RESULT_DROPPED, $plan->result);
        $this->assertSame(RoutingResult::DROPPED, $plan->outcome);
        $this->assertSame('Reply to non-existent chat', $plan->reason());
    }

    public function test_chat_reply_from_an_unknown_tn_id_is_skipped_not_filed_on_the_named_account(): void
    {
        $poster = $this->createTestUser();
        // A shared account: it holds another member's TN id, and TN names it for this one.
        $shared = $this->tnUser();
        $chat = $this->createTestChatRoom($poster, $shared);

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply',
            'tn_user_id' => $shared->tnuserid + 1,
            'fd_user_id' => $shared->id,
            'fd_chat_id' => $chat->id,
        ]));

        $this->assertSame(ChatIngestPlan::RESULT_SKIPPED, $plan->result);
        $this->assertSame('tn-sender-unknown', $plan->reason());
    }

    public function test_chat_reply_uses_the_named_account_when_it_holds_no_tn_id(): void
    {
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $chat = $this->createTestChatRoom($poster, $member);

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply', 'tn_user_id' => 88_000_001, 'fd_user_id' => $member->id, 'fd_chat_id' => $chat->id,
        ]));

        $this->assertSame(ChatIngestPlan::RESULT_WRITE, $plan->result);
        $this->assertSame($member->id, $plan->senderId);
    }

    public function test_chat_reply_from_someone_not_in_the_chat_is_skipped(): void
    {
        $chat = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $stranger = $this->tnUser();

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply', 'tn_user_id' => $stranger->tnuserid, 'fd_chat_id' => $chat->id,
        ]));

        $this->assertSame(ChatIngestPlan::RESULT_SKIPPED, $plan->result);
        $this->assertSame('tn-sender-not-in-chat', $plan->reason());
    }

    public function test_chat_reply_with_no_chat_id_is_incomplete(): void
    {
        $plan = $this->service->plan($this->record(['kind' => 'chat_reply', 'tn_user_id' => 1]));

        $this->assertSame('tn-incomplete-record', $plan->reason());
        $this->assertSame('fd_chat_id', $plan->context['missing']);
    }

    public function test_reply_after_a_held_message_is_held_unless_read_as_of_before_it(): void
    {
        $poster = $this->createTestUser();
        $member = $this->tnUser();
        $chat = $this->createTestChatRoom($poster, $member);
        $earlier = $this->createTestChatMessage($chat, $poster);
        $held = $this->createTestChatMessage($chat, $poster, ['reviewrequired' => 1]);
        $record = $this->record(['kind' => 'chat_reply', 'tn_user_id' => $member->tnuserid, 'fd_chat_id' => $chat->id]);

        $now = $this->service->plan($record);
        $this->assertTrue($now->reviewRequired);
        $this->assertSame('Last', $now->reportReason);

        // As the message stood when its email copy arrived, just after $earlier.
        $then = $this->service->plan($record, $held->id);
        $this->assertFalse($then->reviewRequired);
        $this->assertTrue($earlier->id < $held->id);
    }

    public function test_review_check_holds_text_mentioning_money(): void
    {
        $poster = $this->createTestUser();
        $member = $this->tnUser();
        $chat = $this->createTestChatRoom($poster, $member);

        $plan = $this->service->plan($this->record([
            'kind' => 'chat_reply', 'tn_user_id' => $member->tnuserid, 'fd_chat_id' => $chat->id,
            'text' => 'I can give you £20 for it if you like.',
        ]));

        $this->assertTrue($plan->reviewRequired);
        $this->assertSame('Money', $plan->reportReason);
    }

    public function test_post_reply_is_interested_with_refmsgid_and_creates_the_chat_later(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $post = $this->createTestMessage($poster, $group);
        $member = $this->tnUser();

        $plan = $this->service->plan($this->record([
            'kind' => 'post_reply',
            'tn_user_id' => $member->tnuserid,
            'fd_msgid' => $post->id,
            'subject' => 'Re: ' . $post->subject,
        ]));

        $this->assertSame(ChatIngestPlan::RESULT_WRITE, $plan->result);
        $this->assertSame(ChatMessage::TYPE_INTERESTED, $plan->messageType);
        $this->assertSame($post->id, $plan->refmsgid);
        $this->assertSame($poster->id, $plan->recipientId);
        $this->assertNull($plan->chatId);
        $this->assertSame(ChatRoom::TYPE_USER2USER, $plan->chatType);
        $this->assertFalse($plan->touchLastAccess);
        // The subject stays off a reply tied to a post.
        $this->assertStringNotContainsString('Re:', $plan->text);
    }

    public function test_post_reply_finds_an_existing_chat_in_either_user_order(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $post = $this->createTestMessage($poster, $group);
        $member = $this->tnUser();
        $chat = $this->createTestChatRoom($member, $poster);

        $plan = $this->service->plan($this->record([
            'kind' => 'post_reply', 'tn_user_id' => $member->tnuserid, 'fd_msgid' => $post->id,
            'fd_recipient_user_id' => $poster->id,
        ]));

        $this->assertSame($chat->id, $plan->chatId);
        $this->assertSame($chat->id, $plan->context['chat_id']);
        $this->assertSame($poster->id, $plan->context['to_user_id']);
    }

    public function test_message_not_about_a_post_is_default_with_the_subject_in_front(): void
    {
        $recipient = $this->createTestUser();
        $member = $this->tnUser();

        $plan = $this->service->plan($this->record([
            'kind' => 'user_message', 'tn_user_id' => $member->tnuserid,
            'fd_recipient_user_id' => $recipient->id, 'subject' => 'About your bookcase',
            'text' => 'Do you still have the bookcase?',
        ]));

        $this->assertSame(ChatMessage::TYPE_DEFAULT, $plan->messageType);
        $this->assertNull($plan->refmsgid);
        $this->assertSame("About your bookcase\r\n\r\nDo you still have the bookcase?", $plan->text);
    }

    public function test_direct_message_drops(): void
    {
        $member = $this->tnUser();

        $self = $this->service->plan($this->record([
            'kind' => 'user_message', 'tn_user_id' => $member->tnuserid, 'fd_recipient_user_id' => $member->id,
        ]));
        $this->assertSame('Direct mail to self', $self->reason());

        $unknown = $this->service->plan($this->record([
            'kind' => 'user_message', 'tn_user_id' => $member->tnuserid, 'fd_recipient_user_id' => 999_999_999,
        ]));
        $this->assertSame('Direct mail to unknown user address', $unknown->reason());

        $missing = $this->service->plan($this->record(['kind' => 'user_message', 'tn_user_id' => $member->tnuserid]));
        $this->assertSame('tn-incomplete-record', $missing->reason());

        DB::table('spam_users')->insert(['userid' => $member->id, 'collection' => 'Spammer', 'reason' => 'test']);
        $spammer = $this->service->plan($this->record([
            'kind' => 'user_message', 'tn_user_id' => $member->tnuserid, 'fd_recipient_user_id' => $this->createTestUser()->id,
        ]));
        $this->assertSame('Known spammer', $spammer->reason());
    }

    public function test_volunteers_message_goes_to_the_user2mod_chat(): void
    {
        $group = $this->createTestGroup();
        $member = $this->tnUser();

        $new = $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => $group->id,
            'subject' => 'A question', 'text' => 'How do I change my community?',
        ]));

        $this->assertSame(RoutingResult::TO_VOLUNTEERS, $new->outcome);
        $this->assertNull($new->chatId);
        $this->assertSame(ChatRoom::TYPE_USER2MOD, $new->chatType);
        $this->assertSame($group->id, $new->groupId);
        $this->assertSame($group->nameshort, $new->context['group_name']);
        $this->assertTrue($new->touchLastAccess);
        $this->assertSame('How do I change my community?', $new->text);

        $chat = ChatRoom::create(['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $member->id, 'groupid' => $group->id]);
        $existing = $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => $group->id,
        ]));
        $this->assertSame($chat->id, $existing->chatId);
    }

    public function test_a_report_keeps_its_transcript_and_a_volunteers_message_does_not(): void
    {
        $group = $this->createTestGroup();
        $member = $this->tnUser();
        $text = "This member is asking for money.\n> I want £50 first\n> or no deal";

        $report = $this->service->plan($this->record([
            'kind' => 'report', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => $group->id,
            'subject' => 'Reporting member "x"', 'text' => $text,
        ]));
        $volunteers = $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => $group->id,
            'subject' => 'Help', 'text' => $text,
        ]));

        $this->assertSame($text, $report->text);
        $this->assertSame('This member is asking for money.', $volunteers->text);
    }

    public function test_volunteers_drops(): void
    {
        $member = $this->tnUser();
        $group = $this->createTestGroup();

        $this->assertSame('Volunteers message to unknown group', $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => 999_999_999,
        ]))->reason());

        $this->assertSame('tn-incomplete-record', $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => $member->tnuserid,
        ]))->reason());

        $this->assertSame('tn-sender-unknown', $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => 77_000_001, 'fd_group_id' => $group->id,
        ]))->reason());

        $member->update(['deleted' => now()]);
        $this->assertSame('Volunteers message from deleted user', $this->service->plan($this->record([
            'kind' => 'volunteers', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => $group->id,
        ]))->reason());
    }

    public function test_tn_notices_and_unknown_kinds_are_skipped(): void
    {
        $this->assertSame('tn-notice-not-carried', $this->service->plan($this->record(['kind' => 'tn_notice']))->reason());

        $unknown = $this->service->plan($this->record(['kind' => 'something_new']));
        $this->assertSame(ChatIngestPlan::RESULT_SKIPPED, $unknown->result);
        $this->assertSame('tn-unknown-kind', $unknown->reason());
    }

    public function test_a_record_already_written_is_a_duplicate(): void
    {
        $poster = $this->createTestUser();
        $member = $this->tnUser();
        $chat = $this->createTestChatRoom($poster, $member);
        $msg = $this->createTestChatMessage($chat, $member);
        $record = $this->record(['kind' => 'chat_reply', 'tn_user_id' => $member->tnuserid, 'fd_chat_id' => $chat->id]);
        ChatMessageTn::create(['chatmsgid' => $msg->id, 'tnmessageid' => $record->id]);

        $plan = $this->service->plan($record);

        $this->assertSame(ChatIngestPlan::RESULT_DUPLICATE, $plan->result);
        $this->assertSame('duplicate', $plan->reason());
    }

    public function test_plan_writes_nothing(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $post = $this->createTestMessage($poster, $group);
        $member = $this->tnUser();
        $before = [DB::table('chat_rooms')->count(), DB::table('chat_messages')->count(), DB::table('chat_roster')->count()];

        $this->service->plan($this->record(['kind' => 'post_reply', 'tn_user_id' => $member->tnuserid, 'fd_msgid' => $post->id]));
        $this->service->plan($this->record(['kind' => 'volunteers', 'tn_user_id' => $member->tnuserid, 'fd_group_id' => $group->id]));

        $this->assertSame($before, [DB::table('chat_rooms')->count(), DB::table('chat_messages')->count(), DB::table('chat_roster')->count()]);
    }

    public function test_record_needs_an_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TnChatMessage::fromArray(['kind' => 'chat_reply']);
    }

    public function test_record_log_id_falls_back_to_its_own_id(): void
    {
        $this->assertSame('a', TnChatMessage::fromArray(['id' => 'a'])->logId());
        $this->assertSame('b', TnChatMessage::fromArray(['id' => 'a', 'email_log_id' => 'b'])->logId());
    }
}
