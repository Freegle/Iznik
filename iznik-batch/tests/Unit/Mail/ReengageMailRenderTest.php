<?php

namespace Tests\Unit\Mail;

use App\Mail\Reengage\ReengageMail;
use App\Services\ReengageContentService;
use Tests\TestCase;

/**
 * Renders the first-week onboarding tip template. (Kept under the historical
 * "reengage" file/class names; the feature is onboarding.)
 */
class ReengageMailRenderTest extends TestCase
{
    private function data(int $day): array
    {
        return (new ReengageContentService())->previewContent($day, 'alex@example.com');
    }

    // ── Blade/MJML source rendering (cheap; no MJML compile) ─────────────────

    public function test_day1_sets_expectation_and_shows_good_offer_tip(): void
    {
        $html = view('emails.mjml.reengage.tip', $this->data(1))->render();

        $this->assertStringContainsString('Welcome to Freegle', $html);
        $this->assertStringContainsString('one short tip a day', $html);   // expectation-setting
        $this->assertStringContainsString('Day 1 of 5', $html);            // progress
        $this->assertStringContainsString('What makes a good offer', $html);
        $this->assertStringContainsString('Offer something', $html);       // CTA
        $this->assertStringContainsString('alex@example.com', $html);      // footer recipient

        // We don't tell people to state where they are: postcode-based posting
        // fills in the area automatically, and personal details belong in chat,
        // not a public post.
        $this->assertStringNotContainsString('Roughly where you are', $html);
        $this->assertStringNotContainsString('so people know if they can collect', $html);
    }

    public function test_day2_challenges_the_nobody_wants_it_instinct(): void
    {
        $html = view('emails.mjml.reengage.tip', $this->data(2))->render();

        $this->assertStringContainsString('nobody', $html);
        $this->assertStringContainsString('cupboard', $html);
        $this->assertStringContainsString('Offer something', $html);
    }

    public function test_day3_encourages_a_wanted_post(): void
    {
        $html = view('emails.mjml.reengage.tip', $this->data(3))->render();

        $this->assertStringContainsString('Post a wanted', $html);
        $this->assertStringContainsString('/find', $html);   // wanted CTA target

        // Same as the offer tip: no "state your location" advice - the area comes
        // from the poster's postcode.
        $this->assertStringNotContainsString('Roughly where you are', $html);
    }

    public function test_day4_promotes_search(): void
    {
        $html = view('emails.mjml.reengage.tip', $this->data(4))->render();

        $this->assertStringContainsString('search', $html);
        $this->assertStringContainsString('Search Freegle', $html);
    }

    public function test_day5_gives_safe_neighbourly_collection_guidance(): void
    {
        // The wrap-up tip carries the safety/etiquette message that hundreds of
        // groups put in their own welcome mails: arrange privately in chat (not a
        // public post), keep to a doorstep pickup, and use common sense.
        $html = view('emails.mjml.reengage.tip', $this->data(5))->render();

        // Heading contains an apostrophe which Blade escapes in HTML, so match an
        // apostrophe-free substring.
        $this->assertStringContainsString('freegler now', $html);
        $this->assertStringContainsString('in Freegle chat rather than in a public post', $html);
        $this->assertStringContainsString('doorstep pickup', $html);
        $this->assertStringContainsString('common sense', $html);
    }

    public function test_every_tip_carries_a_volunteer_signoff(): void
    {
        for ($day = 1; $day <= ReengageContentService::TIPS; $day++) {
            $html = view('emails.mjml.reengage.tip', $this->data($day))->render();

            $this->assertStringContainsString('Priya', $html, "day {$day} volunteer name");
            $this->assertStringContainsString('Your local Freegle volunteer', $html, "day {$day} sign-off");
            $this->assertStringContainsString('Edinburgh Freegle', $html, "day {$day} group");
        }
    }

    public function test_signoff_falls_back_to_freegle_team_without_a_volunteer(): void
    {
        $data = array_merge($this->data(1), ['volunteerName' => null, 'volunteerGroup' => null]);

        $html = view('emails.mjml.reengage.tip', $data)->render();

        $this->assertStringContainsString('The Freegle team', $html);
        $this->assertStringNotContainsString('Your local Freegle volunteer', $html);
    }

    public function test_template_escapes_user_supplied_content(): void
    {
        $data = array_merge($this->data(1), ['name' => '<script>alert(1)</script>']);

        $html = view('emails.mjml.reengage.tip', $data)->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ── Full MJML compile + Mailable wiring ──────────────────────────────────

    public function test_tip_mailable_compiles_to_html(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'tip', 0, $this->data(1));

        $html = $mail->render();

        $this->assertStringContainsString('Offer something', $html);
        $this->assertStringContainsString('<html', strtolower($html)); // compiled, not raw MJML
        $this->assertStringNotContainsString('<mjml>', strtolower($html));
    }

    public function test_email_type_is_reengage(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'tip', 5, $this->data(1));

        $this->assertSame('Reengage', $mail->getEmailType());
    }

    public function test_plain_text_alternative_renders(): void
    {
        $text = view('emails.text.reengage.tip', $this->data(1))->render();

        $this->assertStringContainsString('Welcome to Freegle', $text);
        $this->assertStringContainsString('Offer something', $text);
        $this->assertStringContainsString('Your local Freegle volunteer', $text);
        $this->assertStringNotContainsString('<mj-', $text);
    }
}
