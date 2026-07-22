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

    public function test_day1_gives_safe_neighbourly_collection_guidance(): void
    {
        // The safety/etiquette message that hundreds of groups put in their own
        // welcome mails - arrange privately in chat (not a public post), keep to a
        // doorstep handover, use common sense - lands on day 1: a new member can be
        // arranging their first pickup within the hour of their first offer, so it
        // must not wait until the day-5 wrap-up.
        $html = view('emails.mjml.reengage.tip', $this->data(1))->render();

        $this->assertStringContainsString('doorstep handover', $html);
        $this->assertStringContainsString('in Freegle chat rather than in a public post', $html);
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

    // ── Link tracking (effectiveness measurement) ────────────────────────────

    /**
     * A real send (userId > 0) must route EVERY clickable link the template
     * renders through a tracked redirect (/e/d/r/...), so the onboarding funnel's
     * click-through is measurable: the per-day primary button plus the footer
     * settings and unsubscribe links. (Opens are covered separately by the pixel.)
     */
    public function test_real_send_tracks_every_rendered_link(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'tip', 987654321, $this->data(1));

        $html = $mail->render();

        // Tracked-redirect wrapper present at all.
        $this->assertStringContainsString('/e/d/r/', $html);
        // Primary CTA button, with the give/find/browse distinction preserved in
        // the click action (day 1's CTA is /give).
        $this->assertStringContainsString('p=primary_cta', $html);
        $this->assertStringContainsString('a=give', $html);
        // Footer links.
        $this->assertStringContainsString('p=footer_settings', $html);
        $this->assertStringContainsString('p=footer_unsubscribe', $html);
    }

    /**
     * The tracked CTA follows the day's destination: day 3 points at /find.
     */
    public function test_cta_action_matches_the_day_destination(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'tip', 987654321, $this->data(3));

        $html = $mail->render();

        $this->assertStringContainsString('p=primary_cta', $html);
        $this->assertStringContainsString('a=find', $html);
    }

    /**
     * Previews (userId 0) stay deliberately untracked so sample renders never
     * pollute the effectiveness stats: links remain raw.
     */
    public function test_preview_leaves_links_untracked(): void
    {
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'tip', 0, $this->data(1));

        $html = $mail->render();

        $this->assertStringNotContainsString('/e/d/r/', $html);
        $this->assertStringContainsString('/give', $html);   // raw CTA destination
    }

    /**
     * Tracking the VISIBLE unsubscribe link must not disturb the keyed one-click
     * URL that addListUnsubscribeHeaders() reads from $this->content for the RFC
     * 8058 List-Unsubscribe header: build() must wrap only its local copy. A
     * tracked redirect in that header would break no-session one-click
     * unsubscribe from Gmail/Yahoo.
     */
    public function test_real_send_keeps_keyed_unsubscribe_url_for_header(): void
    {
        $data = $this->data(1);
        $mail = new ReengageMail('Alex', 'alex@example.com', 'Subject', 'tip', 987654321, $data);

        $mail->render();

        // The property the List-Unsubscribe header is built from is untouched.
        $this->assertSame($data['unsubscribeUrl'], $mail->content['unsubscribeUrl']);
        $this->assertStringNotContainsString('/e/d/r/', $mail->content['unsubscribeUrl']);
    }

    /**
     * The reworked day-1 condition tip drops the awkward "honest is perfect"
     * phrasing for the clearer "even broken things can find a home" wording.
     */
    public function test_day1_condition_tip_uses_reworded_copy(): void
    {
        $html = view('emails.mjml.reengage.tip', $this->data(1))->render();

        $this->assertStringContainsString('even broken things can find a home', $html);
        $this->assertStringNotContainsString('honest is perfect', $html);
    }
}
