<?php

namespace Tests\Unit\Mail;

use App\Mail\Chat\ChatNotification;
use App\Mail\Digest\UnifiedDigest;
use App\Mail\Donation\AskForDonation;
use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\UnifiedDigestService;
use Tests\TestCase;

/**
 * The "Donating helps too!" CTA link must point to the user-site /donate page,
 * not to the old freegle.in/paypal1510 short URL whose destination 403s.
 *
 * Correct destination: config('freegle.sites.user') . '/donate'
 * (same pattern as the working browse, settings, and unsubscribe links).
 *
 * Note: email links are wrapped in tracking redirect URLs. The actual destination
 * is base64-encoded in the `url` query parameter:
 *   {api_base}/e/d/r/{tracking_id}?url={base64_destination}&p=...&a=...
 *
 * So we assert the base64-encoded form of the expected URL appears in the HTML,
 * and that the base64-encoded form of the old broken URL does NOT appear.
 */
class DonateUrlTest extends TestCase
{
    private function expectedDonateUrl(): string
    {
        return config('freegle.sites.user') . '/donate';
    }

    /**
     * Assert the rendered HTML contains a tracking-redirect link whose
     * destination is the expected donate URL.
     *
     * Tracking wraps destination URLs as: ?url=<base64($destinationUrl)>
     * We check that the HTML contains the base64-encoded expected URL.
     */
    private function assertHtmlContainsDonateUrl(string $html, string $context): void
    {
        $expectedUrl     = $this->expectedDonateUrl();
        $encodedExpected = base64_encode($expectedUrl);

        $this->assertStringContainsString(
            $encodedExpected,
            $html,
            "{$context}: rendered HTML must contain a tracking URL whose destination is user_site/donate (encoded as base64 in the ?url= param)"
        );
    }

    /**
     * Assert neither the raw old URL nor its base64-encoded form appears.
     */
    private function assertHtmlNotContainsBrokenDonateUrl(string $html, string $context): void
    {
        $this->assertStringNotContainsString(
            'freegle.in/paypal1510',
            $html,
            "{$context}: HTML must not contain the raw old freegle.in/paypal1510 URL"
        );

        $this->assertStringNotContainsString(
            base64_encode('https://freegle.in/paypal1510'),
            $html,
            "{$context}: HTML must not contain the base64-encoded old freegle.in/paypal1510 URL"
        );
    }

    /** @test */
    public function test_unified_digest_donate_url_points_to_user_site_donate_page(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        // MODE_IMMEDIATE (single-post "immediate" email) always includes the donate
        // CTA: when there are no job ads it shows a standalone "Donating helps too!"
        // button, so the test does not depend on the user having nearby job ads.
        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $html = $mail->render();

        $this->assertHtmlContainsDonateUrl($html, 'UnifiedDigest');
        $this->assertHtmlNotContainsBrokenDonateUrl($html, 'UnifiedDigest');
    }

    /** @test */
    public function test_chat_notification_donate_url_points_to_user_site_donate_page(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);
        $chatMessage = $this->createTestChatMessage($room, $user1, [
            'message' => 'Is the sofa still available?',
            'type'    => ChatMessage::TYPE_DEFAULT,
        ]);

        // The donate button in ChatNotification is only rendered when there are
        // job ads. Mock getJobAds() on the recipient so the jobs section renders
        // and we can verify the donate URL destination.
        $fakeJob = (object) [
            'id'        => 99,
            'title'     => 'Volunteer Coordinator',
            'location'  => 'Testville',
            'image_url' => null,
        ];
        $user2 = \Mockery::mock($user2)->makePartial();
        $user2->shouldReceive('getJobAds')->andReturn(['jobs' => collect([$fakeJob])]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            $chatMessage,
            ChatRoom::TYPE_USER2USER
        );

        $html = $mail->render();

        $this->assertHtmlContainsDonateUrl($html, 'ChatNotification');
        $this->assertHtmlNotContainsBrokenDonateUrl($html, 'ChatNotification');
    }

    /** @test */
    public function test_volunteering_digest_donate_url_points_to_user_site_donate_page(): void
    {
        $userSite = config('freegle.sites.user');

        // The donate button in the volunteering digest is only shown when job ads
        // are present (it lives inside the @if($jobAds->isNotEmpty()) block).
        // Pass a minimal stub object so the section renders and we can verify
        // the donate link destination.
        $fakeJob = (object) [
            'id'        => 99,
            'title'     => 'Volunteer Coordinator',
            'location'  => 'Testville',
            'image_url' => null,
        ];

        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            groupName:      'Testville Freegle',
            volunteerings:  [[
                'id'             => 1,
                'title'          => 'Help at food bank',
                'location'       => 'Town Hall',
                'description'    => 'We need helpers.',
                'timecommitment' => '2 hours',
                'online'         => false,
                'contactname'    => null,
                'contactphone'   => null,
                'contactemail'   => null,
                'contacturl'     => null,
                'photo_thumb'    => null,
                'applyby'        => null,
                'url'            => $userSite . '/volunteering/1',
            ]],
            unsubscribeUrl: $userSite . '/unsubscribe?email=test%40example.com',
            jobAds: collect([$fakeJob]),
        );

        $html = $mail->render();

        $this->assertHtmlContainsDonateUrl($html, 'VolunteeringDigestMail');
        $this->assertHtmlNotContainsBrokenDonateUrl($html, 'VolunteeringDigestMail');
    }

    /** @test */
    public function test_ask_for_donation_donate_url_points_to_user_site_donate_page(): void
    {
        $user = $this->createTestUser();
        $mail = new AskForDonation($user);

        // Test the property directly (not wrapped by trackedUrl in the mailable).
        $this->assertStringContainsString(
            '/donate',
            $mail->donateUrl,
            'AskForDonation::$donateUrl must contain /donate'
        );
        $this->assertStringNotContainsString(
            'paypal1510',
            $mail->donateUrl,
            'AskForDonation::$donateUrl must not reference paypal1510'
        );

        $html = $mail->render();

        $this->assertHtmlContainsDonateUrl($html, 'AskForDonation');
        $this->assertHtmlNotContainsBrokenDonateUrl($html, 'AskForDonation');
    }
}
