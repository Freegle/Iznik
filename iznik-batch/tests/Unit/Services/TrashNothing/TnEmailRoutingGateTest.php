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
}
