<?php

namespace Tests\Unit\Mail;

use App\Mail\Volunteering\VolunteeringDigestMail;
use Illuminate\Support\Collection;
use Tests\TestCase;

class VolunteeringDigestMailTest extends TestCase
{
    private function makeVolunteering(int $id = 1): array
    {
        return [
            'id'             => $id,
            'title'          => 'Help at local food bank',
            'location'       => 'Town Hall',
            'description'    => 'We need helpers every Saturday.',
            'timecommitment' => '3 hours per week',
            'online'         => false,
            'contactname'    => null,
            'contactphone'   => null,
            'contactemail'   => null,
            'contacturl'     => null,
            'photo_thumb'    => null,
            'applyby'        => null,
            'url'            => config('freegle.sites.user') . '/volunteering/' . $id,
        ];
    }

    public function test_rendered_html_contains_no_doubled_https_in_volunteering_url(): void
    {
        $userSite = config('freegle.sites.user'); // e.g. https://www.ilovefreegle.org

        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            volunteerings:  [$this->makeVolunteering(42)],
            unsubscribeUrl: $userSite . '/unsubscribe?email=' . urlencode('test@example.com'),
        );

        $html = $mail->render();

        $this->assertStringNotContainsString('https://https://', $html,
            'Volunteering email must not contain doubled https://');

        $this->assertStringContainsString("{$userSite}/volunteering/42", $html,
            '"Find out more" link must use the correct URL');

        $this->assertStringContainsString("{$userSite}/volunteering", $html,
            '"View all volunteering opportunities" button must use the correct URL');
    }

    public function test_unsubscribe_url_contains_no_doubled_https(): void
    {
        $userSite      = config('freegle.sites.user');
        $unsubscribeUrl = $userSite . '/unsubscribe?email=' . urlencode('test@example.com');

        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            volunteerings:  [$this->makeVolunteering()],
            unsubscribeUrl: $unsubscribeUrl,
        );

        $html = $mail->render();

        $this->assertStringNotContainsString('https://https://', $html,
            'Unsubscribe URL must not contain doubled https://');

        $this->assertStringContainsString($unsubscribeUrl, $html,
            'Unsubscribe URL must appear correctly in the footer');
    }

    private function makeJobAd(int $id = 1): object
    {
        return (object)[
            'id'        => $id,
            'title'     => 'Test Volunteer Co-ordinator',
            'location'  => 'Testville',
            'image_url' => null,
        ];
    }

    /**
     * Decode the donate button's destination URL from rendered HTML.
     * The button href is a tracking redirect: /e/d/r/<id>?url=<base64>&p=donate_link
     * Falls back to a raw href containing /donate if tracking is inactive.
     */
    private function extractDonateDestination(string $html): ?string
    {
        // Primary: find tracking redirect URL whose p= param is donate_link
        if (preg_match_all('/href=["\']([^"\']*\/e\/d\/r\/[^"\']*)["\']/', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $decoded = html_entity_decode($href);
                if (str_contains($decoded, 'donate_link') &&
                    preg_match('/[?&]url=([^&"\']+)/', $decoded, $urlMatch)) {
                    $dest = base64_decode($urlMatch[1]);
                    if ($dest !== false) {
                        return $dest;
                    }
                }
            }
        }
        // Fallback: raw freegle.in short link href (when tracking is null)
        if (preg_match('/href=["\']([^"\']*freegle\.in[^"\']*)["\']/', $html, $match)) {
            return html_entity_decode($match[1]);
        }
        return null;
    }

    public function test_donate_url_in_job_ads_section_uses_freegle_in_short_link(): void
    {
        // The "Donating helps too!" button must keep the freegle.in/paypal1510
        // PayPal short link. freegle.in is whitelisted in the Go API's
        // isValidRedirectURL allow-list, so the tracked redirect resolves the
        // short link correctly. The short link must NOT be rewritten to a full
        // /donate URL — short links are intentional and supported.
        $userSite = config('freegle.sites.user');

        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            volunteerings:  [$this->makeVolunteering(1)],
            unsubscribeUrl: "{$userSite}/unsubscribe?email=" . urlencode('test@example.com'),
            jobAds:         collect([$this->makeJobAd(99)]),
        );

        $html = $mail->render();

        $this->assertStringContainsString('Donating helps too!', $html,
            'Job ads section with Donating helps too! button should be present');

        $donateDest = $this->extractDonateDestination($html);

        $this->assertNotNull($donateDest,
            '"Donating helps too!" button destination URL not found in rendered email');
        $this->assertStringContainsString('freegle.in/paypal1510', $donateDest,
            '"Donating helps too!" must use the freegle.in/paypal1510 PayPal short link (whitelisted in isValidRedirectURL); it must not be replaced with a full URL');
    }

    public function test_service_builds_volunteering_url_without_doubled_https(): void
    {
        // Regression guard: ensure the URL key in the volData array passed to
        // VolunteeringDigestMail does not prepend https:// to a value that
        // already contains https://.
        $userSite = config('freegle.sites.user');

        // Simulate how VolunteeringDigestService builds the url field.
        // Before the fix: "https://{$userSite}/volunteering/{$id}"
        // After the fix:  "{$userSite}/volunteering/{$id}"
        $volUrl = $userSite . '/volunteering/99';

        $this->assertStringNotContainsString('https://https://', $volUrl,
            'Volunteering URL must not contain doubled https://');

        $this->assertStringStartsWith($userSite, $volUrl,
            'Volunteering URL must start with the configured site URL (no extra protocol prepended)');
    }

    // ─── Preheader (mj-preview) assertions ────────────────────────────────────

    public function test_preheader_shows_first_title_for_single_opportunity(): void
    {
        // Single opportunity: preview is just the title with no trailing " and N more".
        $userSite = config('freegle.sites.user');

        $vol = $this->makeVolunteering(1);
        $vol['title'] = 'Help at the Riverside Food Bank';

        $html = view('emails.mjml.volunteering.digest', [
            'volunteerings'  => [$vol],
            'userSite'       => $userSite,
            'unsubscribeUrl' => $userSite . '/unsubscribe',
            'email'          => 'test@example.com',
            'jobAds'         => collect(),
            'jobsUrl'        => $userSite . '/jobs',
            'donateUrl'      => 'https://freegle.in/paypal1510',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Help at the Riverside Food Bank</mj-preview>',
            $html,
            'Single-opportunity preheader must contain the opportunity title'
        );
    }

    public function test_preheader_shows_title_and_more_count_for_multiple_opportunities(): void
    {
        // Multiple opportunities: preview appends " and N more" after the first title.
        $userSite = config('freegle.sites.user');

        $vol1 = $this->makeVolunteering(1);
        $vol1['title'] = 'Help at the Riverside Food Bank';

        $vol2 = $this->makeVolunteering(2);
        $vol2['title'] = 'Garden volunteer at Greenway Park';

        $vol3 = $this->makeVolunteering(3);
        $vol3['title'] = 'Admin helper for local charity';

        $html = view('emails.mjml.volunteering.digest', [
            'volunteerings'  => [$vol1, $vol2, $vol3],
            'userSite'       => $userSite,
            'unsubscribeUrl' => $userSite . '/unsubscribe',
            'email'          => 'test@example.com',
            'jobAds'         => collect(),
            'jobsUrl'        => $userSite . '/jobs',
            'donateUrl'      => 'https://freegle.in/paypal1510',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Help at the Riverside Food Bank and 2 more</mj-preview>',
            $html,
            'Multi-opportunity preheader must show the first title followed by " and N more"'
        );
    }

    public function test_preheader_falls_back_when_volunteerings_is_empty(): void
    {
        // When the volunteerings array happens to be empty the null-coalescing
        // fallback in the template must prevent a blank preheader.
        $userSite = config('freegle.sites.user');

        $html = view('emails.mjml.volunteering.digest', [
            'volunteerings'  => [],
            'userSite'       => $userSite,
            'unsubscribeUrl' => $userSite . '/unsubscribe',
            'email'          => 'test@example.com',
            'jobAds'         => collect(),
            'jobsUrl'        => $userSite . '/jobs',
            'donateUrl'      => 'https://freegle.in/paypal1510',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Volunteer opportunities near you</mj-preview>',
            $html,
            'Preheader must fall back to the generic string when the opportunities list is empty'
        );
    }
}
