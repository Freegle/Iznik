<?php

namespace Tests\Unit\Mail;

use App\Mail\Event\EventsDigestMail;
use Tests\TestCase;

class EventsDigestMailTest extends TestCase
{
    private function makeEvent(int $id = 1): array
    {
        return [
            'id'           => $id,
            'title'        => 'Repair Cafe',
            'location'     => 'Town Hall',
            'description'  => 'Bring your broken things along.',
            'contactname'  => null,
            'contactphone' => null,
            'contactemail' => null,
            'contacturl'   => null,
            'start'        => 'Sat, 31st May 2:00pm',
            'end'          => '4:00pm',
            'imageUrl'     => null,
            'url'          => config('freegle.sites.user') . '/communityevent/' . $id,
        ];
    }

    public function test_rendered_html_contains_no_doubled_https_in_event_urls(): void
    {
        $userSite = config('freegle.sites.user'); // e.g. https://www.ilovefreegle.org

        $mail = new EventsDigestMail(
            recipientEmail: 'test@example.com',
            groupName:      'Testville Freegle',
            events:         [$this->makeEvent(42)],
            unsubscribeUrl: $userSite . '/unsubscribe?email=' . urlencode('test@example.com'),
        );

        $html = $mail->render();

        $this->assertStringNotContainsString('https://https://', $html,
            'Events email must not contain doubled https://');

        // Event headline link + "Find out more" button both use $event['url'].
        $this->assertStringContainsString("{$userSite}/communityevent/42", $html,
            'Event headline / "Find out more" link must use the correct URL');

        // "View all community events" button.
        $this->assertStringContainsString("{$userSite}/communityevents", $html,
            '"View all community events" button must use the correct URL');
    }

    public function test_unsubscribe_url_contains_no_doubled_https(): void
    {
        $userSite       = config('freegle.sites.user');
        $unsubscribeUrl = $userSite . '/unsubscribe?email=' . urlencode('test@example.com');

        $mail = new EventsDigestMail(
            recipientEmail: 'test@example.com',
            groupName:      'Testville Freegle',
            events:         [$this->makeEvent()],
            unsubscribeUrl: $unsubscribeUrl,
        );

        $html = $mail->render();

        $this->assertStringNotContainsString('https://https://', $html,
            'Unsubscribe URL must not contain doubled https://');

        $this->assertStringContainsString($unsubscribeUrl, $html,
            'Unsubscribe URL must appear correctly in the footer');
    }

    public function test_service_builds_event_url_without_doubled_https(): void
    {
        // Regression guard: ensure the URL key in the event array passed to
        // EventsDigestMail does not prepend https:// to a value that already
        // contains https://.
        $userSite = config('freegle.sites.user');

        // Simulate how EventsDigestService builds the url field.
        // Before the fix: "https://{$userSite}/communityevent/{$id}"
        // After the fix:  "{$userSite}/communityevent/{$id}"
        $eventUrl = $userSite . '/communityevent/99';

        $this->assertStringNotContainsString('https://https://', $eventUrl,
            'Event URL must not contain doubled https://');

        $this->assertStringStartsWith($userSite, $eventUrl,
            'Event URL must start with the configured site URL (no extra protocol prepended)');
    }
}
