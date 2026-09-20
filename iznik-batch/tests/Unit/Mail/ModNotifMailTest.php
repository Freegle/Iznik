<?php

namespace Tests\Unit\Mail;

use App\Mail\Admin\ModNotifMail;
use Tests\TestCase;

class ModNotifMailTest extends TestCase
{
    public function test_preheader_shows_subject_when_provided(): void
    {
        // The inbox preview carries the mod-notif subject (e.g. "MODERATE: 5 things
        // to do") so the moderator sees how much is waiting before opening the email.
        $mail = new ModNotifMail(
            recipientName: 'Alice Mod',
            recipientEmail: 'alice@example.com',
            recipientUserId: null,
            subject: 'MODERATE: 5 things to do on FreegleSomewhere',
            htmlSummary: '<p>There are 5 items awaiting your attention.</p>',
            textSummary: 'There are 5 items awaiting your attention.',
        );

        $html = $mail->render();

        $this->assertStringContainsString(
            'MODERATE: 5 things to do on FreegleSomewhere',
            $html,
            'Preheader must show the count-bearing subject so the moderator sees how much is waiting'
        );
    }

    public function test_preheader_falls_back_to_generic_text_when_subject_absent(): void
    {
        // When the subject is empty the template falls back to the static default
        // "There's stuff to do on ModTools" rather than leaving the preview blank.
        $html = view('emails.mjml.admin.mod-notif', [
            'recipientName' => 'Bob Mod',
            'email'         => 'bob@example.com',
            'htmlSummary'   => '<p>Some items await.</p>',
            'settingsUrl'   => 'https://modtools.org/settings',
            // modNotifSubject absent — triggers the ?? fallback in the template
        ])->render();

        $this->assertStringContainsString(
            "There's stuff to do on ModTools",
            $html,
            'Preheader must fall back to the static text when no subject is supplied'
        );
    }
}
