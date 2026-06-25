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
            title: $title,
            // A loginLink()-style auto-login URL pointing at the opportunity.
            renewUrl: $userSite . '/volunteering/42?u=7&k=abcdef&src=voldigest',
            groupName: 'Freegle Testville',
            userId: 7,
        );
    }

    public function test_subject_is_regarding_the_title(): void
    {
        // Mirrors iznik-server Volunteering::askRenew(): "Regarding: {title}".
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
        $this->assertStringContainsString('/volunteering/42?u=7', $html,
            'The renewal login link must appear in the email');
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
            'title'          => 'Litter-pick helpers needed',
            'renewUrl'       => $userSite . '/volunteering/77?u=3&k=xyz',
            'groupName'      => 'Freegle Riverside',
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
