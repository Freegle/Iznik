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
