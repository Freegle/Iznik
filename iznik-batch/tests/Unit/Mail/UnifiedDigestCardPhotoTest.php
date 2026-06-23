<?php

namespace Tests\Unit\Mail;

use App\Services\UnifiedDigestService;
use Tests\TestCase;

/**
 * Guards that the per-item card photo is clickable in both template variants.
 *
 * The AMP digest card photo was NOT wrapped in a link, so Gmail users could
 * see the photo but couldn't click it to reach the post page.  test_amp_digest_card_photo_links_to_message_page
 * verifies the fix; the three remaining tests are guards that ensure we
 * don't accidentally break the already-working cases.
 */
class UnifiedDigestCardPhotoTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the minimal top-level data array that both templates need.
     *
     * @param  \Illuminate\Support\Collection $posts  The fabricated post array.
     * @param  string                         $mode   MODE_DAILY or MODE_IMMEDIATE.
     * @return array
     */
    private function templateData(\Illuminate\Support\Collection $posts, string $mode): array
    {
        $firstPost = $posts->first();
        $isOffer   = ($firstPost['type'] ?? 'Offer') === 'Offer';

        return [
            'user'           => (object) [
                'email_preferred' => 'r@example.com',
                'displayname'     => 'Sam',
                'id'              => 1,
            ],
            'posts'          => $posts,
            'post'           => $firstPost, // immediate mode uses $post (singular)
            'postCount'      => $posts->count(),
            'mode'           => $mode,
            'isOffer'        => $isOffer,
            'accentColor'    => $isOffer ? '#338808' : '#00A1CB',
            'sponsors'       => collect(),
            'jobAds'         => collect(),
            'jobsUrl'        => 'https://example.com/jobs',
            'donateUrl'      => 'https://example.com/donate',
            'browseUrl'      => 'https://example.com/browse',
            'myPostsUrl'     => 'https://example.com/myposts',
            'settingsUrl'    => 'https://example.com/settings',
            'unsubscribeUrl' => 'https://example.com/unsubscribe',
            'userSite'       => 'https://example.com',
            'siteName'       => 'Freegle',
            // AMP-only (used by amp-form action-xhr per post — already in $post['ampReplyUrl'])
            'ampPostMeta'    => $posts->mapWithKeys(
                fn ($p) => [(int) $p['message']->id => [
                    't' => $p['itemName'],
                    'k' => '',
                    'e' => 0,
                ]]
            )->toArray(),
            'ampApiUrl'      => 'https://api.ilovefreegle.org/amp',
            'ampUserId'      => 1,
        ];
    }

    /**
     * Build a single fabricated post array with all keys both templates use.
     *
     * messageUrl contains a base64 query param to ensure regex-special characters
     * are handled correctly in assertions (via preg_quote).
     *
     * @param  int    $id   Unique message id.
     * @param  string $type 'Offer' or 'Wanted'.
     * @return array
     */
    private function makePost(int $id, string $type = 'Offer'): array
    {
        // Deliberately put regex-special characters in the URL to test preg_quote
        $trackedBase  = 'https://www.ilovefreegle.org/message/' . $id;
        $messageUrl   = 'https://api.ilovefreegle.org/e/d/r/TOKEN' . $id . '?url=' . base64_encode($trackedBase);

        // fallbackReplyUrl is identical to messageUrl in UnifiedDigest
        // (see UnifiedDigest::preparePost ~line 773).
        $fallbackUrl  = $messageUrl;
        $thumbUrl     = 'https://images.ilovefreegle.org/timg_' . $id . '.jpg';
        $heroUrl      = 'https://images.ilovefreegle.org/img_' . $id . '.jpg';
        $displayUrl   = 'https://delivery.ilovefreegle.org/img_' . $id . '.jpg';
        $avatarUrl    = 'https://images.ilovefreegle.org/avatar_' . $id . '.jpg';
        $ampReplyUrl  = 'https://api.ilovefreegle.org/amp/reply/' . $id;

        // Summary index ("In this digest") link: its own tracked URL to the
        // message page, distinct from messageUrl (which carries ?reply=1).
        $summaryUrl = 'https://api.ilovefreegle.org/e/d/r/STOKEN' . $id . '?url=' . base64_encode($trackedBase);

        return [
            'message'          => (object) ['id' => $id],
            'type'             => $type,
            'itemName'         => 'Test Item ' . $id,
            'subject'          => strtoupper($type) . ': Test Item ' . $id . ' (Edinburgh)',
            'thumbImageUrl'    => $thumbUrl,
            'heroImageUrl'     => $heroUrl,
            'displayImageUrl'  => $displayUrl,
            'messageUrl'       => $messageUrl,
            'summaryUrl'       => $summaryUrl,
            'fallbackReplyUrl' => $fallbackUrl,
            'locationName'     => 'Edinburgh',
            'distanceText'     => '1.2 miles',
            'arrivalFormatted' => '2 hours ago',
            'posterName'       => 'Alice',
            'posterAvatarUrl'  => $avatarUrl,
            'groupName'        => 'FreegleTown',
            'groupUrl'         => 'https://www.ilovefreegle.org/explore/fregletown',
            'messageText'      => 'A lovely test item',
            'postedToText'     => '',
            'firstPostedFormatted' => null,
            'isPlaceholder'    => false,
            'ampReplyUrl'      => $ampReplyUrl,
        ];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * THE BUG FIX: in the multi-post (daily) AMP card the photo must be
     * wrapped in an anchor so Gmail users can click through to the post page.
     *
     * The assertion anchors on the .post-img-wrap class rather than on the
     * image src alone, because the header-thumbnail strip ALSO renders
     * thumbImageUrl + fallbackReplyUrl — asserting on src alone would pass
     * even without the fix and prove nothing.
     *
     * This test MUST FAIL before the AMP template fix and PASS after it.
     */
    public function test_amp_digest_card_photo_links_to_message_page(): void
    {
        $post1 = $this->makePost(111, 'Offer');
        $post2 = $this->makePost(222, 'Wanted');
        $posts = collect([$post1, $post2]);

        $html = view(
            'emails.amp.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_DAILY)
        )->render();

        // For each post: the .post-img-wrap div must contain an <a> link that
        // wraps the <amp-img>.  The header-thumb strip also has thumbImageUrl +
        // fallbackReplyUrl, so we anchor on post-img-wrap to target ONLY the card.
        foreach ([$post1, $post2] as $post) {
            $quotedFallback = preg_quote($post['fallbackReplyUrl'], '/');

            $this->assertMatchesRegularExpression(
                '/class="post-img-wrap">\s*<a\s+href="' . $quotedFallback . '"[^>]*>\s*<amp-img/s',
                $html,
                "Card photo for post {$post['message']->id} is not wrapped in a clickable link to the message page."
            );
        }
    }

    /**
     * GUARD: the single-post (immediate) AMP hero photo is already clickable;
     * ensure we don't break it.
     */
    public function test_amp_immediate_hero_photo_links_to_message_page(): void
    {
        $post  = $this->makePost(333, 'Offer');
        $posts = collect([$post]);

        $html = view(
            'emails.amp.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $quotedFallback = preg_quote($post['fallbackReplyUrl'], '/');
        $quotedHero     = preg_quote($post['heroImageUrl'], '/');

        $this->assertMatchesRegularExpression(
            '/<a\s+href="' . $quotedFallback . '"[^>]*>\s*<amp-img[^>]*src="' . $quotedHero . '"/s',
            $html,
            'Single-post (immediate) AMP hero photo should be wrapped in a link to the message page.'
        );
    }

    /**
     * GUARD: the MJML daily card photo already has href set; ensure it stays.
     */
    public function test_mjml_digest_card_photo_links_to_message_page(): void
    {
        $post1 = $this->makePost(444, 'Offer');
        $post2 = $this->makePost(555, 'Wanted');
        $posts = collect([$post1, $post2]);

        // Rendering the MJML view returns raw MJML markup (not compiled HTML),
        // which is sufficient to assert attribute presence without needing mrml.
        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_DAILY)
        )->render();

        foreach ([$post1, $post2] as $post) {
            $quotedThumb   = preg_quote($post['thumbImageUrl'], '/');
            $quotedMsgUrl  = preg_quote($post['messageUrl'], '/');

            // MJML daily card: <mj-image src="THUMB" href="MSGURL" .../>
            $this->assertMatchesRegularExpression(
                '/<mj-image\s+src="' . $quotedThumb . '"\s+href="' . $quotedMsgUrl . '"/s',
                $mjml,
                "MJML daily card photo for post {$post['message']->id} should link to the message page."
            );
        }
    }

    /**
     * GUARD: the MJML immediate hero photo already has href set; ensure it stays.
     */
    public function test_mjml_immediate_hero_photo_links_to_message_page(): void
    {
        $post  = $this->makePost(666, 'Offer');
        $posts = collect([$post]);

        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $quotedMsgUrl  = preg_quote($post['messageUrl'], '/');
        $quotedDisplay = preg_quote($post['displayImageUrl'], '/');

        // MJML immediate hero: <mj-image href="MSGURL" src="DISPLAY" .../>
        $this->assertMatchesRegularExpression(
            '/<mj-image\s+href="' . $quotedMsgUrl . '"\s+src="' . $quotedDisplay . '"/s',
            $mjml,
            'MJML immediate hero photo should link to the message page.'
        );
    }
}
