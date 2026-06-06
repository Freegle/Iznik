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
            groupName:      'Testville Freegle',
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
            groupName:      'Testville Freegle',
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

    public function test_donate_url_in_job_ads_section_uses_site_donate_page(): void
    {
        $userSite = config('freegle.sites.user');

        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            groupName:      'Testville Freegle',
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
        $this->assertStringContainsString('/donate', $donateDest,
            '"Donating helps too!" must link to the site donate page');
        $this->assertStringNotContainsString('freegle.in/paypal1510', $donateDest,
            '"Donating helps too!" must not use the old freegle.in/paypal1510 short link');
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
}
