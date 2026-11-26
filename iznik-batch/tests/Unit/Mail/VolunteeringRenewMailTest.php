<?php

namespace Tests\Unit\Mail;

use App\Mail\Volunteering\VolunteeringRenewMail;
use Tests\TestCase;

class VolunteeringRenewMailTest extends TestCase
{
    private function makeMail(string $title = 'Help at the community garden'): VolunteeringRenewMail
    {
        $userSite = config('freegle.sites.user');

        return new VolunteeringRenewMail(
            recipientEmail: 'owner@example.com',
            opportunities: [[
                'id' => 42,
                'title' => $title,
                'groupName' => 'Freegle Testville',
                // A loginLink()-style auto-login URL pointing at the opportunity.
                'renewUrl' => $userSite . '/volunteering/42?u=7&k=abcdef&src=voldigest',
            ]],
            userId: 7,
        );
    }

    private function makeMultiMail(): VolunteeringRenewMail
    {
        $userSite = config('freegle.sites.user');

        return new VolunteeringRenewMail(
            recipientEmail: 'owner@example.com',
            opportunities: [
                ['id' => 42, 'title' => 'Garden help', 'groupName' => 'Freegle Testville',
                    'renewUrl' => $userSite . '/volunteering/42?u=7&k=abcdef'],
                ['id' => 43, 'title' => 'Beach clean', 'groupName' => 'Freegle Seaton',
                    'renewUrl' => $userSite . '/volunteering/43?u=7&k=abcdef'],
            ],
            userId: 7,
        );
    }

    public function test_multiple_opportunities_listed_in_one_email_with_own_links(): void
    {
        $mail = $this->makeMultiMail();

        $this->assertSame('Are your 2 volunteer opportunities still active?', $mail->envelope()->subject);

        $html = $mail->render();
        $this->assertStringContainsString('Garden help', $html);
        $this->assertStringContainsString('Beach clean', $html);
        $this->assertStringContainsString('Freegle Seaton', $html);
        $this->assertStringContainsString($this->encoded('/volunteering/42?u=7&k=abcdef'), $html);
        $this->assertStringContainsString($this->encoded('/volunteering/43?u=7&k=abcdef'), $html);
    }

    /** The tracked link carries its destination base64'd and percent-encoded. */
    private function encoded(string $path): string
    {
        return rawurlencode(base64_encode(config('freegle.sites.user') . $path));
    }

    public function test_tracking_row_is_written(): void
    {
        $user = $this->createTestUser();
        $mail = new VolunteeringRenewMail(
            recipientEmail: 'owner@example.com',
            opportunities: [['id' => 42, 'title' => 'T', 'groupName' => 'G', 'renewUrl' => 'https://x.test/v']],
            userId: $user->id,
        );

        $this->assertNotNull($mail->getTracking());
        $this->assertSame('VolunteeringRenew', $mail->getTracking()->email_type);
        $this->assertSame((int) $user->id, (int) $mail->getTracking()->userid);
    }

    public function test_subject_is_regarding_the_title(): void
    {
        // Mirrors the legacy V1 PHP Volunteering::askRenew(): "Regarding: {title}".
        $mail = $this->makeMail('Beach clean-up');

        $this->assertSame('Regarding: Beach clean-up', $mail->envelope()->subject);
    }

    public function test_rendered_html_contains_title_group_and_renew_link(): void
    {
        $mail = $this->makeMail('Help at the community garden');

        $html = $mail->render();

        $this->assertStringContainsString('Help at the community garden', $html,
            'The opportunity title must appear in the email');
        $this->assertStringContainsString('Freegle Testville', $html,
            'The group name must appear in the heading');
        // Robust against & being escaped to &amp; in the rendered href/text:
        // assert on the path plus first query param only.
        $this->assertStringContainsString($this->encoded('/volunteering/42?u=7&k=abcdef&src=voldigest'), $html,
            'The renewal login link must appear in the email as a tracked link');
        $this->assertStringContainsString('still active', $html,
            'The body must ask whether the opportunity is still active');
        $this->assertStringContainsString('Let us know', $html,
            'The call-to-action button label must match V1 ("Let us know")');
    }

    public function test_rendered_html_has_no_doubled_https(): void
    {
        $html = $this->makeMail()->render();

        $this->assertStringNotContainsString('https://https://', $html,
            'Renewal email must not contain doubled https://');
    }

    // ─── Preheader (mj-preview) assertions ────────────────────────────────────

    public function test_preheader_contains_title_and_group_name(): void
    {
        // The preheader is: "Please confirm: {title} on {groupName} - is it still active?"
        // Assert both the dynamic title and the group name appear so inbox preview
        // clearly identifies which listing needs attention.
        $userSite = config('freegle.sites.user');

        $html = view('emails.mjml.volunteering.renew', [
            'opportunities'  => [[
                'id' => 77,
                'title' => 'Litter-pick helpers needed',
                'renewUrl' => $userSite . '/volunteering/77?u=3&k=xyz',
                'groupName' => 'Freegle Riverside',
            ]],
            'email'          => 'owner@example.com',
            'unsubscribeUrl' => $userSite . '/unsubscribe',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Please confirm: Litter-pick helpers needed on Freegle Riverside - is it still active?</mj-preview>',
            $html,
            'Renewal preheader must name the opportunity title and the group so the inbox preview is informative'
        );
    }
}
