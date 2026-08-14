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

    private function parse(string $envelopeTo, array $headers = []): \App\Services\Mail\Incoming\ParsedEmail
    {
        $lines = [
            'From: Poster <poster@user.trashnothing.com>',
            'To: ' . $envelopeTo,
            'Subject: OFFER: Bookshelf (Camden)',
            'Message-ID: <tn-test@trashnothing.com>',
            'Date: Fri, 14 Aug 2026 09:00:00 +0000',
        ];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $raw = implode("\r\n", $lines) . "\r\n\r\nFree to collect.\r\n";

        return app(MailParserService::class)->parse($raw, 'poster@user.trashnothing.com', $envelopeTo);
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

    public function test_flag_off_means_nothing_is_skipped(): void
    {
        config(['freegle.trashnothing.verify_coverage.skip_email_routing' => false]);

        $email = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);

        $this->assertTrue($this->gate()->isTrashNothingGroupPost($email));
        $this->assertFalse($this->gate()->shouldSkipRouting($email));
    }

    public function test_flag_on_skips_only_tn_group_posts(): void
    {
        config(['freegle.trashnothing.verify_coverage.skip_email_routing' => true]);

        $tnPost = $this->parse($this->groupAddress('camdengroup'), [
            'X-Trash-Nothing-Post-Id' => '47102958',
        ]);
        $memberPost = $this->parse($this->groupAddress('camdengroup'));

        $this->assertTrue($this->gate()->shouldSkipRouting($tnPost));
        $this->assertFalse($this->gate()->shouldSkipRouting($memberPost));
    }
}
