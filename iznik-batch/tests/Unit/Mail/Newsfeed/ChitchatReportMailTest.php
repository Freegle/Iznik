<?php

namespace Tests\Unit\Mail\Newsfeed;

use App\Mail\Newsfeed\ChitchatReportMail;
use Tests\TestCase;

class ChitchatReportMailTest extends TestCase
{
    public function test_subject_contains_reporter_info(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Jane Doe',
            reporterId: 42,
            reporterEmail: 'jane@example.com',
            newsfeedId: 123,
            reason: 'Spam content',
        );

        $envelope = $mail->envelope();
        $this->assertEquals(
            'Jane Doe #42 (jane@example.com) has reported a ChitChat thread',
            $envelope->subject
        );
    }

    public function test_from_address_is_geeks(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Test',
            reporterId: 1,
            reporterEmail: 'test@test.com',
            newsfeedId: 1,
            reason: 'Test',
        );

        $envelope = $mail->envelope();
        $this->assertEquals(config('freegle.mail.geeks_addr'), $envelope->from->address);
    }

    public function test_builds_with_mjml_template(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Test User',
            reporterId: 99,
            reporterEmail: 'test@test.com',
            newsfeedId: 456,
            reason: 'Inappropriate language',
        );

        // Build should not throw - verifies template exists and renders.
        $builtMail = $mail->build();
        $this->assertNotNull($builtMail);
    }

    public function test_sent_to_chitchat_support(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Reporter',
            reporterId: 10,
            reporterEmail: 'reporter@test.com',
            newsfeedId: 789,
            reason: 'Offensive',
        );

        $builtMail = $mail->build();

        // The mail should be addressed to the chitchat support address.
        $this->assertTrue($builtMail->hasTo(config('freegle.mail.chitchat_support_addr')));
    }

    public function test_preheader_contains_reporter_name_and_reason(): void
    {
        // The mj-preview should name the reporter and show a snippet of the reason
        // so moderators can triage the report from the inbox preview alone.
        $html = view('emails.mjml.newsfeed.chitchat-report', [
            'reporterName'  => 'Jane Doe',
            'reporterId'    => 42,
            'reporterEmail' => 'jane@example.com',
            'newsfeedId'    => 123,
            'reason'        => 'This post contains offensive language',
            'threadUrl'     => 'https://www.ilovefreegle.org/chitchat/123',
            'siteName'      => 'Freegle',
        ])->render();

        $this->assertStringContainsString('<mj-preview>Reported by Jane Doe: This post contains offensive language</mj-preview>', $html);
    }

    public function test_preheader_truncates_long_reason(): void
    {
        // Reasons longer than 70 characters should be truncated with "..." so the
        // preview tag stays concise enough for inbox display.
        $longReason = 'This is a very long reason that definitely exceeds seventy characters and should be cut';
        $html = view('emails.mjml.newsfeed.chitchat-report', [
            'reporterName'  => 'Bob Smith',
            'reporterId'    => 7,
            'reporterEmail' => 'bob@example.com',
            'newsfeedId'    => 99,
            'reason'        => $longReason,
            'threadUrl'     => 'https://www.ilovefreegle.org/chitchat/99',
            'siteName'      => 'Freegle',
        ])->render();

        // The full long reason must not appear verbatim in the preview.
        $this->assertStringNotContainsString('<mj-preview>Reported by Bob Smith: ' . $longReason . '</mj-preview>', $html);
        // The reporter name prefix must still be present.
        $this->assertStringContainsString('Reported by Bob Smith:', $html);
        // Truncation marker must appear in the preview element.
        $this->assertMatchesRegularExpression('/<mj-preview>Reported by Bob Smith:.*\.\.\.<\/mj-preview>/', $html);
    }
}
