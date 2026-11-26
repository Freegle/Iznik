<?php

namespace Tests\Unit\Mail\Tryst;

use App\Mail\Tryst\TrystCalendarInviteMail;
use Tests\TestCase;

class TrystCalendarInviteMailTest extends TestCase
{
    private function makeMail(string $title = 'Handover: Sofa'): TrystCalendarInviteMail
    {
        $user = $this->createTestUser();

        return new TrystCalendarInviteMail(
            title: $title,
            calendarLink: 'https://www.ilovefreegle.org/calendar?data=abc123',
            recipientUserId: $user->id,
            recipientName: $user->fullname,
            recipientEmail: $user->email_preferred,
        );
    }

    public function test_preheader_contains_handover_arranged_and_title(): void
    {
        // The template sets preview = 'Handover arranged: ' . $title.
        // Rendering the Blade view (before MJML compilation) is sufficient to
        // verify the <mj-preview> tag carries the dynamic value.
        $user = $this->createTestUser();
        $title = 'Handover: Corner Sofa (London)';

        $html = view('emails.mjml.tryst.calendar-invite', [
            'title'          => $title,
            'calendarLink'   => 'https://www.ilovefreegle.org/calendar?data=abc123',
            'email'          => $user->email_preferred,
            'settingsUrl'    => config('freegle.sites.user') . '/settings',
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe/' . $user->id,
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Handover arranged: Handover: Corner Sofa (London)</mj-preview>',
            $html,
            'Preheader must include the handover title so the inbox preview names the item'
        );
    }

    public function test_preheader_uses_provided_title_verbatim(): void
    {
        // Ensure the dynamic part of the preview exactly matches what was passed
        // so there is no accidental truncation or escaping at the Blade layer.
        $user  = $this->createTestUser();
        $title = 'Tea Set (Bristol)';

        $html = view('emails.mjml.tryst.calendar-invite', [
            'title'          => $title,
            'calendarLink'   => 'https://www.ilovefreegle.org/calendar?data=abc123',
            'email'          => $user->email_preferred,
            'settingsUrl'    => config('freegle.sites.user') . '/settings',
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe/' . $user->id,
        ])->render();

        $this->assertStringContainsString('Handover arranged: Tea Set (Bristol)', $html);
    }

    public function test_mailable_builds_without_error(): void
    {
        $mail = $this->makeMail();

        $result = $mail->build();

        $this->assertInstanceOf(TrystCalendarInviteMail::class, $result);
    }

    public function test_subject_includes_title(): void
    {
        $mail = $this->makeMail('Wardrobe (Edinburgh)');

        $envelope = $mail->envelope();

        $this->assertStringContainsString('Wardrobe (Edinburgh)', $envelope->subject);
    }

    public function test_to_address_matches_recipient(): void
    {
        $user = $this->createTestUser();
        $mail = new TrystCalendarInviteMail(
            title: 'Bike (Leeds)',
            calendarLink: 'https://www.ilovefreegle.org/calendar?data=xyz',
            recipientUserId: $user->id,
            recipientName: $user->fullname,
            recipientEmail: $user->email_preferred,
        );

        $envelope = $mail->envelope();

        $this->assertSame($user->email_preferred, $envelope->to[0]->address);
    }
}
