<?php

namespace Tests\Unit\Mail\Engage;

use App\Mail\Engage\EngageMail;
use Tests\TestCase;

class EngageMissingMailTest extends TestCase
{
    private function viewData(): array
    {
        return [
            'name'           => 'Alice',
            'email'          => 'alice@example.com',
            'engageId'       => 42,
            'userSite'       => 'https://www.ilovefreegle.org',
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe/abc',
        ];
    }

    public function test_missing_template_preheader_contains_re_engagement_text(): void
    {
        $html = view('emails.mjml.engage.missing', $this->viewData())->render();

        $this->assertStringContainsString(
            '<mj-preview>We miss you - could we tempt you back?</mj-preview>',
            $html,
            'The engage/missing preheader must carry the re-engagement teaser text'
        );
    }

    public function test_can_be_constructed_and_built(): void
    {
        $mail = new EngageMail(
            recipientName:  'Alice',
            recipientEmail: 'alice@example.com',
            emailSubject:   'We miss you on Freegle!',
            template:       'missing',
            engageId:       42,
            unsubscribeUrl: 'https://www.ilovefreegle.org/unsubscribe/abc',
        );

        $result = $mail->build();

        $this->assertInstanceOf(EngageMail::class, $result);
    }
}
