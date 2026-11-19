<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Verify\TnEmailRoutingGate;
use Tests\TestCase;

/**
 * The gate decides which inbound mail stops being routed after the TN API
 * cutover. Everything it matches is silently not routed, so these tests are
 * mostly about what it must NOT match — see plans/tn-api-post-ingestion.md
 * section S.2.
 */
class TnEmailRoutingGateTest extends TestCase
{
    private function gate(): TnEmailRoutingGate
    {
        return new TnEmailRoutingGate();
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array{subject?: string, from?: string, envelopeFrom?: string}  $overrides
     */
    private function parse(string $envelopeTo, array $headers = [], array $overrides = []): \App\Services\Mail\Incoming\ParsedEmail
    {
        $from = $overrides['from'] ?? 'Poster <poster@user.trashnothing.com>';
        $envelopeFrom = $overrides['envelopeFrom'] ?? 'poster@user.trashnothing.com';

        $lines = [
            'From: ' . $from,
            'To: ' . $envelopeTo,
            'Subject: ' . ($overrides['subject'] ?? 'OFFER: Bookshelf (Camden)'),
            'Message-ID: <tn-test@trashnothing.com>',
            'Date: Fri, 14 Aug 2026 09:00:00 +0000',
        ];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $raw = implode("\r\n", $lines) . "\r\n\r\nFree to collect.\r\n";

        return app(MailParserService::class)->parse($raw, $envelopeFrom, $envelopeTo);
    }

    private function groupAddress(string $localPart): string
    {
        return $localPart . '@' . config('freegle.mail.group_domain');
    }

    public function test_matches_a_tn_group_post(): void
    {
        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);

        $this->assertTrue($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_a_group_post_that_is_not_from_tn(): void
    {
        // An ordinary member posting by email must keep routing.
        $email = $this->parse($this->groupAddress('camdengroup'));

        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_volunteers_address_even_with_the_tn_header(): void
    {
        // MailParserService strips '-volunteers' and still reports a
        // targetGroupName, so a predicate testing that alone would swallow this
        // — but route() sends it to handleVolunteersMessage() in Phase 4, before
        // group posts are ever considered.
        $email = $this->parse($this->groupAddress('camdengroup-volunteers'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);

        $this->assertTrue($email->isToVolunteers, 'fixture should reach the volunteers branch');
        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_auto_address_even_with_the_tn_header(): void
    {
        $email = $this->parse($this->groupAddress('camdengroup-auto'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);

        $this->assertTrue($email->isToAuto, 'fixture should reach the auto branch');
        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_mail_that_is_not_to_a_group_at_all(): void
    {
        $email = $this->parse('someone@example.com', [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);

        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_a_bounce_to_a_group_address(): void
    {
        // route() Phase 3 claims bounces before group posts, and handleBounce()
        // does real work — recording the bounce, and eventually turning a
        // member's mail off. Skipping one would lose that silently.
        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ], ['subject' => 'Mail delivery failed: returning message to sender']);

        $this->assertTrue($email->isBounce(), 'fixture should reach the bounce branch');
        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_a_digest_reply(): void
    {
        // Phase 3c: replies to a digest get an explanatory auto-response, which
        // is not something the API path replaces.
        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
            'In-Reply-To' => '<UnifiedDigest-1234@users.ilovefreegle.org>',
        ]);

        $this->assertTrue($email->isDigestReply(), 'fixture should reach the digest-reply branch');
        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_an_auto_reply(): void
    {
        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
            'Auto-Submitted' => 'auto-replied',
        ]);

        $this->assertTrue($email->isAutoReply(), 'fixture should reach the auto-reply branch');
        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_a_self_sent_message(): void
    {
        $groupAddress = $this->groupAddress('camdengroup');
        $email = $this->parse($groupAddress, [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ], ['envelopeFrom' => $groupAddress, 'from' => $groupAddress]);

        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_ignores_a_dropped_sender(): void
    {
        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ], ['from' => 'Twitter <info@twitter.com>', 'envelopeFrom' => 'info@twitter.com']);

        $this->assertFalse($this->gate()->isTrashNothingGroupPost($email));
    }

    public function test_flag_off_means_nothing_is_skipped(): void
    {
        config(['freegle.trashnothing.ingest_posts_via_api' => false]);

        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);

        $this->assertTrue($this->gate()->isTrashNothingGroupPost($email));
        $this->assertFalse($this->gate()->shouldSkipRouting($email));
    }

    public function test_flag_on_skips_only_tn_group_posts(): void
    {
        config(['freegle.trashnothing.ingest_posts_via_api' => true]);

        $tnPost = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);
        $memberPost = $this->parse($this->groupAddress('camdengroup'));

        $this->assertTrue($this->gate()->shouldSkipRouting($tnPost));
        $this->assertFalse($this->gate()->shouldSkipRouting($memberPost));
    }

    // =========================================================================
    // TN chat messages (plans/2026-11-12-tn-api-chat-ingestion.md, section 4.3)
    // =========================================================================

    private function userAddress(string $localPart): string
    {
        return $localPart . '@' . config('freegle.mail.user_domain');
    }

    /** @param array<string, string> $headers */
    private function chatMail(string $envelopeTo, array $headers = [], array $overrides = []): \App\Services\Mail\Incoming\ParsedEmail
    {
        return $this->parse($envelopeTo, $headers, $overrides + [
            'subject' => 'Re: OFFER: Bookshelf (Camden)',
            'from' => 'Replier <replier-g123@user.trashnothing.com>',
            'envelopeFrom' => 'bounce-replier@trashnothing.com',
        ]);
    }

    public function test_chat_kinds_for_each_row_the_feed_carries(): void
    {
        $gate = $this->gate();

        $this->assertSame('chat_reply', $gate->trashNothingChatKind($this->chatMail($this->userAddress('notify-123-456'))));
        $this->assertSame('post_reply', $gate->trashNothingChatKind($this->chatMail($this->userAddress('poster-456'), ['X-FD-MsgId' => '123456789'])));
        $this->assertSame('user_message', $gate->trashNothingChatKind($this->chatMail($this->userAddress('poster-456'))));
        $this->assertSame('volunteers', $gate->trashNothingChatKind($this->chatMail($this->groupAddress('camdengroup-volunteers'), [], ['subject' => 'A question'])));
        $this->assertSame('report', $gate->trashNothingChatKind($this->chatMail($this->groupAddress('camdengroup-volunteers'), [], ['subject' => 'Reporting member "x"'])));
        $this->assertTrue($gate->isTrashNothingChatMessage($this->chatMail($this->userAddress('notify-123-456'))));
    }

    public function test_chat_gate_ignores_mail_not_from_a_tn_member(): void
    {
        $gate = $this->gate();

        // A Freegle member replying by email.
        $member = $this->chatMail($this->userAddress('notify-123-456'), [], ['from' => 'M <member@example.com>', 'envelopeFrom' => 'member@example.com']);
        $this->assertNull($gate->trashNothingChatKind($member));

        // TN's own notice to volunteers (row 9) stays on email until the feed carries it.
        $notice = $this->chatMail($this->groupAddress('camdengroup-volunteers'), [], ['from' => 'Trash Nothing <noreply@trashnothing.com>', 'envelopeFrom' => 'noreply@trashnothing.com']);
        $this->assertNull($gate->trashNothingChatKind($notice));

        // A TN From with a non-TN envelope is not TN's mail.
        $forged = $this->chatMail($this->userAddress('notify-123-456'), [], ['envelopeFrom' => 'someone@example.com']);
        $this->assertNull($gate->trashNothingChatKind($forged));
    }

    public function test_chat_gate_leaves_what_an_earlier_phase_or_the_handler_claims_first(): void
    {
        $gate = $this->gate();

        foreach (['digestoff-456-7', 'eventsoff-456', 'unsubscribe-456-abc-x', 'readreceipt-1-2-3', 'handover-9', 'bounce-456-1700000000', 'replyto-123-456'] as $local) {
            $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->userAddress($local))), $local);
        }

        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->groupAddress('camdengroup-subscribe'))));
        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->groupAddress('camdengroup-auto'))));
        // A group post is not chat.
        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->groupAddress('camdengroup'))));

        // handleChatNotificationReply drops these before writing anything.
        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->userAddress('notify-123-456'), ['Auto-Submitted' => 'auto-replied'])));
        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->userAddress('notify-123-456'), [], ['subject' => 'Out of Office'])));
        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->userAddress('notify-123-456'), ['Content-Type' => 'multipart/report; report-type=disposition-notification'])));

        // route() drops an auto-reply before direct mail is reached.
        $this->assertNull($gate->trashNothingChatKind($this->chatMail($this->userAddress('poster-456'), [], ['subject' => 'Automatic reply'])));

        // Mail to an address that is not a member's on our domain.
        $this->assertNull($gate->trashNothingChatKind($this->chatMail('someone@example.com')));
    }

    public function test_chat_flag_off_skips_no_chat_mail(): void
    {
        config(['freegle.trashnothing.ingest_chat_via_api' => false]);

        $this->assertFalse($this->gate()->shouldSkipRouting($this->chatMail($this->userAddress('notify-123-456'))));
    }

    public function test_chat_flag_on_skips_tn_chat_mail_of_the_kinds_moved_and_nothing_else(): void
    {
        config([
            'freegle.trashnothing.ingest_chat_via_api' => true,
            'freegle.trashnothing.ingest_chat_kinds' => 'chat_reply,post_reply',
        ]);
        $gate = $this->gate();

        $this->assertTrue($gate->shouldSkipRouting($this->chatMail($this->userAddress('notify-123-456'))));
        $this->assertTrue($gate->shouldSkipRouting($this->chatMail($this->userAddress('poster-456'), ['X-FD-MsgId' => '1'])));
        // Not moved yet: still routed.
        $this->assertFalse($gate->shouldSkipRouting($this->chatMail($this->groupAddress('camdengroup-volunteers'))));
        // A Freegle member's reply, and a TN group post with only the chat flag on.
        $this->assertFalse($gate->shouldSkipRouting($this->chatMail($this->userAddress('notify-123-456'), [], ['from' => 'M <m@example.com>', 'envelopeFrom' => 'm@example.com'])));
        $this->assertFalse($gate->shouldSkipRouting($this->parse($this->groupAddress('camdengroup'), ['X-Trash-Nothing-Post-Id' => '1'])));
    }
}
