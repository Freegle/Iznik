<?php

namespace Tests\Unit\Mail;

use App\Mail\Reengage\ReengageMail;
use App\Services\ReengageContentService;
use Tests\TestCase;

class ReengageMailRenderTest extends TestCase
{
    private function data(string $template): array
    {
        return (new ReengageContentService())->previewContent($template, 'alex@example.com');
    }

    // ── Blade/MJML source rendering (cheap; no MJML compile) ─────────────────

    public function test_nearby_template_renders_localised_content(): void
    {
        $html = view('emails.mjml.reengage.nearby', $this->data('nearby'))->render();

        $this->assertStringContainsString('Edinburgh', $html);          // area name
        $this->assertStringContainsString('47', $html);                 // nearby count
        $this->assertStringContainsString("See what's near you", $html); // primary CTA
        $this->assertStringContainsString('Dining chairs', $html);       // a nearby card
        $this->assertStringContainsString('alex@example.com', $html);     // footer recipient line
    }

    public function test_impact_template_renders_stats(): void
    {
        $html = view('emails.mjml.reengage.impact', $this->data('impact'))->render();

        $this->assertStringContainsString('312', $html);   // items rehomed
        $this->assertStringContainsString('1,840kg', $html); // weight saved (number_format)
        $this->assertStringContainsString('Join back in', $html);
    }

    public function test_preferences_template_offers_downgrade_and_unsubscribe(): void
    {
        $html = view('emails.mjml.reengage.preferences', $this->data('preferences'))->render();

        $this->assertStringContainsString('Keep me freegling', $html);
        $this->assertStringContainsString('Email me less often', $html);
        $this->assertStringContainsString('Unsubscribe', $html);
    }

    public function test_templates_escape_user_supplied_content(): void
    {
        $data = $this->data('nearby');
        $data['offers'][0]['subject'] = '<script>alert(1)</script>';

        $html = view('emails.mjml.reengage.nearby', $data)->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_handles_empty_nearby_and_impact_gracefully(): void
    {
        $data = array_merge($this->data('nearby'), [
            'offers' => [],
            'offerCount' => 0,
            'hasLocation' => false,
            'areaName' => null,
            'areaLabel' => 'your area',
        ]);

        $html = view('emails.mjml.reengage.nearby', $data)->render();

        // Still a valid, non-empty MJML document with the fallback copy + CTA.
        $this->assertStringContainsString('<mjml>', $html);
        $this->assertStringContainsString("See what's near you", $html);
    }

    // ── Full MJML compile + Mailable wiring ──────────────────────────────────

    public function test_nearby_mailable_compiles_to_html(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'nearby', 0, $this->data('nearby'));

        $html = $mail->render();

        $this->assertStringContainsString("See what's near you", $html);
        $this->assertStringContainsString('<html', strtolower($html)); // compiled, not raw MJML
        $this->assertStringNotContainsString('<mjml>', strtolower($html));
    }

    public function test_email_type_is_reengage(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'nearby', 5, $this->data('nearby'));

        $this->assertSame('Reengage', $mail->getEmailType());
    }

    public function test_plain_text_alternative_renders(): void
    {
        $text = view('emails.text.reengage.preferences', $this->data('preferences'))->render();

        $this->assertStringContainsString('Keep me freegling', $text);
        $this->assertStringContainsString('Unsubscribe', $text);
        $this->assertStringNotContainsString('<mj-', $text);
    }
}
