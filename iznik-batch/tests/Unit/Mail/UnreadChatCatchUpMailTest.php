<?php

namespace Tests\Unit\Mail;

use App\Mail\Deferrals\UnreadChatCatchUpMail;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The catch-up has to send each half of the summary to the place it can be
 * read. Chats with other members live on the member site; a moderator's
 * chats with members of their groups live in ModTools, and the member site
 * does not list them at all.
 */
class UnreadChatCatchUpMailTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function viewData(int $chats, int $messages, int $modChats, int $modMessages): array
    {
        $mail = new UnreadChatCatchUpMail(
            recipientUserId: 1,
            recipientEmail: 'test@example.com',
            recipientName: 'Test',
            chatCount: $chats,
            messageCount: $messages,
            delayedSince: '10 September',
            provider: 'Virgin Media',
            modChatCount: $modChats,
            modMessageCount: $modMessages,
        );
        $mail->build();

        $property = new ReflectionProperty($mail, 'mjmlData');
        $property->setAccessible(true);

        return $property->getValue($mail);
    }

    public function test_member_chats_link_to_the_member_site(): void
    {
        $data = $this->viewData(chats: 1, messages: 1, modChats: 0, modMessages: 0);

        $this->assertSame(rtrim((string) config('freegle.sites.user'), '/') . '/chats', $data['chatsUrl']);
        $this->assertSame(0, $data['modChatCount']);
    }

    public function test_mod_chats_link_to_modtools(): void
    {
        $data = $this->viewData(chats: 0, messages: 0, modChats: 1, modMessages: 3);

        $this->assertSame(rtrim((string) config('freegle.sites.mod'), '/') . '/chats', $data['modChatsUrl']);
        $this->assertSame(1, $data['modChatCount']);
        $this->assertSame(3, $data['modMessageCount']);
    }

    public function test_the_rendered_email_only_mentions_the_side_that_has_messages(): void
    {
        $mail = new UnreadChatCatchUpMail(
            recipientUserId: 1,
            recipientEmail: 'test@example.com',
            recipientName: 'Test',
            chatCount: 0,
            messageCount: 0,
            delayedSince: '10 September',
            provider: 'Virgin Media',
            modChatCount: 1,
            modMessageCount: 1,
        );
        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Virgin Media stopped accepting our emails on 10 September', $html);
        $this->assertStringContainsString('you moderate', $html);
        $this->assertStringContainsString(rtrim((string) config('freegle.sites.mod'), '/') . '/chats', $html);
        $this->assertStringNotContainsString(rtrim((string) config('freegle.sites.user'), '/') . '/chats"', $html);
    }

    public function test_both_sides_are_rendered_when_both_have_messages(): void
    {
        $mail = new UnreadChatCatchUpMail(
            recipientUserId: 1,
            recipientEmail: 'test@example.com',
            recipientName: 'Test',
            chatCount: 2,
            messageCount: 5,
            delayedSince: '10 September',
            provider: null,
            modChatCount: 1,
            modMessageCount: 1,
        );
        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Your email provider stopped accepting our emails on 10 September', $html);
        $this->assertStringContainsString('5 messages across 2 chats', $html);
        $this->assertStringContainsString('you moderate', $html);
        $this->assertStringContainsString(rtrim((string) config('freegle.sites.user'), '/') . '/chats', $html);
        $this->assertStringContainsString(rtrim((string) config('freegle.sites.mod'), '/') . '/chats', $html);
    }

    public function test_subject_counts_chats_on_both_sides(): void
    {
        $mail = new UnreadChatCatchUpMail(
            recipientUserId: 1,
            recipientEmail: 'test@example.com',
            recipientName: 'Test',
            chatCount: 1,
            messageCount: 1,
            delayedSince: '10 September',
            provider: null,
            modChatCount: 2,
            modMessageCount: 2,
        );

        $this->assertStringContainsString('in 3 chats', $mail->envelope()->subject);
    }
}
