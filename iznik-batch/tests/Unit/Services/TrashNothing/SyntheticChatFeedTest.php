<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Verify\SyntheticChatFeed;
use App\Services\TrashNothing\Verify\TnEmailRoutingGate;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The record TN's chat feed should give for a TN member's chat email, built from the email, for
 * tn:chat-parity-check --feed=email.
 */
class SyntheticChatFeedTest extends TestCase
{
    private function record(string $envelopeTo, array $headers = [], string $subject = 'Re: OFFER: Bookcase (Town)'): ?\App\Services\TrashNothing\Ingestion\TnChatMessage
    {
        $lines = [
            'From: Member Name <member-g1@user.trashnothing.com>',
            'To: ' . $envelopeTo,
            'Subject: ' . $subject,
            'Message-ID: <x@m2.trashnothing.com>',
            'Date: Tue, 17 Nov 2026 10:30:00 +0000',
        ];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $email = app(MailParserService::class)->parse(implode("\r\n", $lines) . "\r\n\r\nHello there.\r\n", 'member-g1@user.trashnothing.com', $envelopeTo);
        $kind = (new TnEmailRoutingGate)->trashNothingChatKind($email);

        return SyntheticChatFeed::fromEmail($email, (string) $kind, CarbonImmutable::parse('2026-11-17 10:31:00', 'UTC'));
    }

    public function test_a_chat_reply_names_the_chat_and_our_id_for_the_member(): void
    {
        $r = $this->record('notify-123-456@' . config('freegle.mail.user_domain'), [
            'X-Trash-Nothing-Log-Id' => 'log-1', 'X-Trash-Nothing-User-Id' => '987654', 'X-FD-MsgId' => '555',
        ]);

        $this->assertSame('log-1', $r->id);
        $this->assertSame('chat_reply', $r->kind);
        $this->assertSame(123, $r->fdChatId);
        $this->assertSame(456, $r->fdUserId);
        $this->assertSame(987654, $r->tnUserId);
        $this->assertSame(555, $r->fdMsgid);
        $this->assertSame('2026-11-17T10:30:00Z', $r->date);
        $this->assertSame('Member Name', $r->displayName);
        $this->assertSame('Hello there.', trim($r->text));
    }

    public function test_a_direct_message_names_the_recipient_and_a_volunteers_message_the_community(): void
    {
        $direct = $this->record('poster-789@' . config('freegle.mail.user_domain'), ['X-Trash-Nothing-Log-Id' => 'log-2', 'X-FD-MsgId' => '42']);
        $this->assertSame('post_reply', $direct->kind);
        $this->assertSame(789, $direct->fdRecipientUserId);
        $this->assertSame(42, $direct->fdMsgid);

        $group = $this->createTestGroup();
        $vol = $this->record($group->nameshort . '-volunteers@' . config('freegle.mail.group_domain'), ['X-Trash-Nothing-Log-Id' => 'log-3'], 'A question');
        $this->assertSame('volunteers', $vol->kind);
        $this->assertSame($group->id, $vol->fdGroupId);
    }

    public function test_mail_without_a_log_id_gives_no_record(): void
    {
        $this->assertNull($this->record('notify-1-2@' . config('freegle.mail.user_domain')));
    }
}
