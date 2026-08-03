<?php

namespace Tests\Unit\Mail\Notification;

use App\Mail\Notification\ChaseUpMail;
use Tests\TestCase;

/**
 * Link targets for the notification chaseup email.
 *
 * Regression: the stories exhort is scheduled with a full URL
 * (https://www.ilovefreegle.org/stories) in users_notifications.url, but the
 * mail prefixed the site unconditionally, producing tracked links to
 * https://www.ilovefreegle.orghttps://www.ilovefreegle.org/stories which 404.
 */
class ChaseUpMailUrlTest extends TestCase
{
    private function makeMail(): ChaseUpMail
    {
        $user = $this->createTestUser();

        return new ChaseUpMail($user, [], 'You have a notification');
    }

    public function test_absolute_exhort_url_is_not_prefixed_with_the_site(): void
    {
        $site = rtrim(config('freegle.sites.user'), '/');

        $url = $this->makeMail()->resolveNotificationUrl([
            'type' => 'Exhort',
            'url' => $site . '/stories',
            'newsfeed' => null,
        ]);

        $this->assertSame($site . '/stories', $url,
            'An absolute Exhort URL must be used as-is, not concatenated onto the site');
    }

    public function test_relative_exhort_url_is_prefixed_with_the_site(): void
    {
        $site = rtrim(config('freegle.sites.user'), '/');

        $url = $this->makeMail()->resolveNotificationUrl([
            'type' => 'Exhort',
            'url' => '/microvolunteering/message/123',
            'newsfeed' => null,
        ]);

        $this->assertSame($site . '/microvolunteering/message/123', $url,
            'A relative Exhort URL must be resolved against the user site');
    }

    public function test_exhort_url_without_leading_slash_is_still_joined_correctly(): void
    {
        $site = rtrim(config('freegle.sites.user'), '/');

        $url = $this->makeMail()->resolveNotificationUrl([
            'type' => 'Exhort',
            'url' => 'stories',
            'newsfeed' => null,
        ]);

        $this->assertSame($site . '/stories', $url,
            'A path without a leading slash must not be glued onto the hostname');
    }

    public function test_newsfeed_notification_links_to_the_thread(): void
    {
        $site = rtrim(config('freegle.sites.user'), '/');

        $url = $this->makeMail()->resolveNotificationUrl([
            'type' => 'CommentOnYourPost',
            'url' => null,
            'newsfeed' => ['id' => 42],
        ]);

        $this->assertSame($site . '/chitchat/42', $url);
    }

    public function test_notification_without_newsfeed_falls_back_to_chitchat(): void
    {
        $site = rtrim(config('freegle.sites.user'), '/');

        $url = $this->makeMail()->resolveNotificationUrl([
            'type' => 'LovedPost',
            'url' => null,
            'newsfeed' => null,
        ]);

        $this->assertSame($site . '/chitchat', $url);
    }
}
