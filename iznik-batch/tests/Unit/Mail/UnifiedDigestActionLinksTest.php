<?php

namespace Tests\Unit\Mail;

use App\Services\UnifiedDigestService;
use Tests\TestCase;

/**
 * Guards that the unified digest email includes Browse and manage-listing links
 * so members can find their posts and cancel them without manually logging in.
 *
 * Regression: Discourse t/9786/25 — the new unified digest shipped with no
 * path to /myposts, forcing members to navigate manually after login.
 */
class UnifiedDigestActionLinksTest extends TestCase
{
    private function templateData(string $mode, array $extra = []): array
    {
        $post = [
            'message'          => (object) ['id' => 42],
            'type'             => 'Offer',
            'itemName'         => 'Test Sofa',
            'subject'          => 'OFFER: Test Sofa (Edinburgh)',
            'thumbImageUrl'    => 'https://images.ilovefreegle.org/timg_1.jpg',
            'heroImageUrl'     => 'https://images.ilovefreegle.org/img_1.jpg',
            'displayImageUrl'  => 'https://delivery.ilovefreegle.org/img_1.jpg',
            'messageUrl'       => 'https://www.ilovefreegle.org/message/42?reply=1',
            'summaryUrl'       => 'https://www.ilovefreegle.org/message/42',
            'fallbackReplyUrl' => 'https://www.ilovefreegle.org/message/42?reply=1',
            'locationName'     => 'Edinburgh',
            'distanceText'     => '2 miles',
            'arrivalFormatted' => 'Mon 1 Jan, 12:00pm',
            'posterName'       => 'Alice',
            'posterAvatarUrl'  => 'https://images.ilovefreegle.org/avatar_1.jpg',
            'groupName'        => 'FreegleTown',
            'groupUrl'         => 'https://www.ilovefreegle.org/explore/1',
            'messageText'      => 'A lovely sofa',
            'postedToText'     => '',
            'firstPostedFormatted' => null,
            'isPlaceholder'    => false,
            'isOwnPost'        => false,
            'metaText'         => null,
            'ampReplyUrl'      => 'https://api.ilovefreegle.org/amp/digest/42/reply',
            'ampReplyToken'    => 'tok',
            'ampReplyUid'      => 1,
            'ampReplyExp'      => 9999999999,
        ];

        $posts = collect([$post]);

        return array_merge([
            'user'           => (object) [
                'email_preferred' => 'member@example.com',
                'displayname'     => 'Alice',
                'id'              => 1,
            ],
            'posts'          => $posts,
            'post'           => $post,
            'postCount'      => 1,
            'mode'           => $mode,
            'isOffer'        => true,
            'accentColor'    => '#338808',
            'sponsors'       => collect(),
            'completedPosts' => collect(),
            'jobAds'         => collect(),
            'jobsUrl'        => 'https://www.ilovefreegle.org/jobs?t=1',
            'donateUrl'      => 'https://freegle.in/donate?t=1',
            'browseUrl'      => 'https://www.ilovefreegle.org/browse?t=1',
            'myPostsUrl'     => 'https://www.ilovefreegle.org/myposts?t=1',
            'settingsUrl'    => 'https://www.ilovefreegle.org/settings?t=1',
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe?t=1',
            'userSite'       => 'https://www.ilovefreegle.org',
            'siteName'       => 'Freegle',
            'morePosts'      => 0,
            'ampPostMeta'    => [42 => ['t' => 'Test Sofa', 'k' => 'tok', 'e' => 9999999999]],
            'ampApiUrl'      => 'https://api.ilovefreegle.org/amp',
            'ampUserId'      => 1,
            'digestGroups'   => collect(),
            'primaryGroupName' => null,
            'frequencyText'  => $mode === UnifiedDigestService::MODE_IMMEDIATE ? 'immediately' : 'daily',
        ], $extra);
    }

    /** Daily MJML digest must include a link to /myposts ("Manage your listings"). */
    public function test_daily_mjml_includes_manage_listings_link(): void
    {
        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData(UnifiedDigestService::MODE_DAILY)
        )->render();

        $this->assertStringContainsString(
            '/myposts',
            $mjml,
            'Daily MJML digest must include a link to /myposts so members can manage or cancel their listings.'
        );
    }

    /** Immediate MJML digest must include a link to /myposts ("Manage your listings"). */
    public function test_immediate_mjml_includes_manage_listings_link(): void
    {
        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData(UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $this->assertStringContainsString(
            '/myposts',
            $mjml,
            'Immediate MJML digest must include a link to /myposts so members can manage or cancel their listings.'
        );
    }

    /** Plain-text digest must include the /myposts URL. */
    public function test_text_digest_includes_manage_listings_url(): void
    {
        $text = view(
            'emails.text.digest.unified',
            $this->templateData(UnifiedDigestService::MODE_DAILY)
        )->render();

        $this->assertStringContainsString(
            '/myposts',
            $text,
            'Plain-text digest must include the /myposts URL so members can manage or cancel their listings.'
        );
    }

    /** AMP digest must include a link to /myposts. */
    public function test_amp_digest_includes_manage_listings_link(): void
    {
        $html = view(
            'emails.amp.digest.unified',
            $this->templateData(UnifiedDigestService::MODE_DAILY)
        )->render();

        $this->assertStringContainsString(
            '/myposts',
            $html,
            'AMP digest must include a link to /myposts so members can manage or cancel their listings.'
        );
    }

    /** Daily digest must still contain the Browse All Posts link (regression guard). */
    public function test_daily_mjml_includes_browse_link(): void
    {
        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData(UnifiedDigestService::MODE_DAILY)
        )->render();

        $this->assertStringContainsString(
            '/browse',
            $mjml,
            'Daily MJML digest must include a Browse link.'
        );
    }

    /** Immediate digest must still contain the Browse link (regression guard). */
    public function test_immediate_mjml_includes_browse_link(): void
    {
        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData(UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $this->assertStringContainsString(
            '/browse',
            $mjml,
            'Immediate MJML digest must include a Browse link.'
        );
    }
}
