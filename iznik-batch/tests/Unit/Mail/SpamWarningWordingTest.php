<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

/**
 * The spam-warning chat email (text and mjml) shows the innocent recipient's own
 * post as $messageSubject, because that post is what the scammer used to make
 * contact. Discourse 10174/1: recipients read that as an accusation about their
 * own item, since the old wording only said "we think you were talking about:
 * <item>" without ever naming the voucher scam. Members threatened to leave.
 *
 * The fix names the voucher scam explicitly and states the item is shown only
 * because it's what the scammer contacted the recipient about, not because
 * anything is wrong with it.
 */
class SpamWarningWordingTest extends TestCase
{
    private function textParams(): array
    {
        return [
            'spammerName'    => 'Suspicious Pete',
            'messageSubject' => 'OFFER: Test item (Test Town)',
        ];
    }

    private function mjmlParams(): array
    {
        return $this->textParams() + ['siteName' => 'Freegle'];
    }

    public function test_text_template_names_the_voucher_scam(): void
    {
        $text = view('emails.text.chat.spam-warning', $this->textParams())->render();

        $this->assertStringContainsString('voucher scam', $text);
    }

    public function test_mjml_template_names_the_voucher_scam(): void
    {
        $html = view('emails.mjml.chat.spam-warning', $this->mjmlParams())->render();

        $this->assertStringContainsString('voucher scam', $html);
    }

    public function test_text_template_does_not_use_old_accusatory_wording(): void
    {
        $text = view('emails.text.chat.spam-warning', $this->textParams())->render();

        $this->assertStringNotContainsString('We think you were talking about', $text);
        $this->assertStringNotContainsString('might be a scammer', $text);
    }

    public function test_mjml_template_does_not_use_old_accusatory_wording(): void
    {
        $html = view('emails.mjml.chat.spam-warning', $this->mjmlParams())->render();

        $this->assertStringNotContainsString('We think you were talking about', $html);
        $this->assertStringNotContainsString('might be a scammer', $html);
    }

    public function test_text_template_clarifies_the_item_is_not_implicated(): void
    {
        $text = view('emails.text.chat.spam-warning', $this->textParams())->render();

        $this->assertStringContainsString('not because there\'s anything wrong with it', $text);
        $this->assertStringContainsString('This email only goes to you', $text);
    }

    public function test_mjml_template_clarifies_the_item_is_not_implicated(): void
    {
        $html = view('emails.mjml.chat.spam-warning', $this->mjmlParams())->render();

        $this->assertStringContainsString('not because there\'s', $html);
        $this->assertStringContainsString('This email only goes to you', $html);
    }

    public function test_templates_still_show_the_item_when_a_subject_is_present(): void
    {
        $text = view('emails.text.chat.spam-warning', $this->textParams())->render();
        $html = view('emails.mjml.chat.spam-warning', $this->mjmlParams())->render();

        $this->assertStringContainsString('OFFER: Test item (Test Town)', $text);
        $this->assertStringContainsString('OFFER: Test item (Test Town)', $html);
    }

    public function test_templates_omit_the_item_block_when_no_subject_is_present(): void
    {
        $params = ['spammerName' => 'Suspicious Pete', 'messageSubject' => null];

        $text = view('emails.text.chat.spam-warning', $params)->render();
        $html = view('emails.mjml.chat.spam-warning', $params + ['siteName' => 'Freegle'])->render();

        $this->assertStringNotContainsString('This email only goes to you', $text);
        $this->assertStringNotContainsString('This email only goes to you', $html);
        $this->assertStringContainsString('voucher scam', $text);
        $this->assertStringContainsString('voucher scam', $html);
    }
}
