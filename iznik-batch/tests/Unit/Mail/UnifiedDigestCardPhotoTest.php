<?php

namespace Tests\Unit\Mail;

use App\Services\UnifiedDigestService;
use Tests\TestCase;

/**
 * Guards that the per-item card photo is clickable AND that clicking it just
 * VIEWS the post — it must use $post['viewUrl'] (the message page, no
 * ?reply=1), never the reply CTA URL. Clicking the photo should show the item,
 * not pop open the reply compose pane; only the Reply button does that.
 *
 * Each test asserts the photo/hero anchor points at viewUrl across both
 * template variants (MJML + AMP) and both modes (immediate hero + daily card);
 * a final test confirms the Reply control still carries the reply URL, so the
 * photo and the Reply button are genuinely different links.
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
     * The URLs deliberately carry regex-special characters (base64 query) so the
     * assertions exercise preg_quote, and each link kind uses a distinct token
     * so a test can prove the photo points at viewUrl and NOT the reply URL.
     *
     * @param  int    $id   Unique message id.
     * @param  string $type 'Offer' or 'Wanted'.
     * @return array
     */
    private function makePost(int $id, string $type = 'Offer'): array
    {
        // Deliberately put regex-special characters in the URL to test preg_quote
        $trackedBase  = 'https://www.ilovefreegle.org/message/' . $id;
        // The reply CTA URL: in production this is the 'm' compact type, which
        // the Go handler reconstructs WITH ?reply=1 (auto-opens the reply pane).
        $messageUrl   = 'https://api.ilovefreegle.org/e/d/r/TOKEN' . $id . '?url=' . base64_encode($trackedBase);

        // fallbackReplyUrl mirrors messageUrl in UnifiedDigest — it is the reply
        // CTA URL (the non-AMP fallback for the AMP in-email reply form).
        $fallbackUrl  = $messageUrl;
        $thumbUrl     = 'https://images.ilovefreegle.org/timg_' . $id . '.jpg';
        $heroUrl      = 'https://images.ilovefreegle.org/img_' . $id . '.jpg';
        $displayUrl   = 'https://delivery.ilovefreegle.org/img_' . $id . '.jpg';
        $avatarUrl    = 'https://images.ilovefreegle.org/avatar_' . $id . '.jpg';
        $ampReplyUrl  = 'https://api.ilovefreegle.org/amp/reply/' . $id;

        // Summary index ("In this digest") link: its own tracked URL to the
        // message page, distinct from messageUrl (which carries ?reply=1).
        $summaryUrl = 'https://api.ilovefreegle.org/e/d/r/STOKEN' . $id . '?url=' . base64_encode($trackedBase);

        // View-only card link used by the photo + title: tracked URL to the
        // message page WITHOUT ?reply=1. Distinct token (VTOKEN) so assertions
        // prove the photo lands on the view URL, not the reply CTA. In
        // production this is the 's' compact type (no ?reply=1 on reconstruct).
        $viewUrl = 'https://api.ilovefreegle.org/e/d/r/VTOKEN' . $id . '?url=' . base64_encode($trackedBase);

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
            'viewUrl'          => $viewUrl,
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
     * The multi-post (daily) AMP card photo must be wrapped in an anchor that
     * links to the VIEW url (so Gmail users can click through to the post page
     * to look at the item — not be dropped into the reply compose pane).
     *
     * The assertion anchors on the .post-img-wrap class rather than on the
     * image src alone, because the header-thumbnail strip ALSO renders
     * thumbImageUrl + viewUrl — asserting on src alone would prove nothing.
     */
    public function test_amp_digest_card_photo_links_to_view_url(): void
    {
        $post1 = $this->makePost(111, 'Offer');
        $post2 = $this->makePost(222, 'Wanted');
        $posts = collect([$post1, $post2]);

        $html = view(
            'emails.amp.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_DAILY)
        )->render();

        foreach ([$post1, $post2] as $post) {
            $quotedView = preg_quote($post['viewUrl'], '/');

            $this->assertMatchesRegularExpression(
                '/class="post-img-wrap">\s*<a\s+href="' . $quotedView . '"[^>]*>\s*<amp-img/s',
                $html,
                "Card photo for post {$post['message']->id} must link to the view URL (no ?reply=1)."
            );

            // It must NOT land on the reply CTA URL.
            $this->assertStringNotContainsString(
                'class="post-img-wrap">' . "\n" . '    <a href="' . $post['messageUrl'] . '"',
                $html,
                "Card photo for post {$post['message']->id} must not link to the reply URL."
            );
        }
    }

    /**
     * The single-post (immediate) AMP hero photo links to the VIEW url.
     */
    public function test_amp_immediate_hero_photo_links_to_view_url(): void
    {
        $post  = $this->makePost(333, 'Offer');
        $posts = collect([$post]);

        $html = view(
            'emails.amp.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $quotedView = preg_quote($post['viewUrl'], '/');
        $quotedHero = preg_quote($post['heroImageUrl'], '/');

        $this->assertMatchesRegularExpression(
            '/<a\s+href="' . $quotedView . '"[^>]*>\s*<amp-img[^>]*src="' . $quotedHero . '"/s',
            $html,
            'Immediate AMP hero photo should link to the view URL (no ?reply=1).'
        );
    }

    /**
     * The MJML daily card photo href points at the VIEW url.
     */
    public function test_mjml_digest_card_photo_links_to_view_url(): void
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
            $quotedThumb = preg_quote($post['thumbImageUrl'], '/');
            $quotedView  = preg_quote($post['viewUrl'], '/');

            // MJML daily card: <mj-image src="THUMB" href="VIEWURL" .../>
            $this->assertMatchesRegularExpression(
                '/<mj-image\s+src="' . $quotedThumb . '"\s+href="' . $quotedView . '"/s',
                $mjml,
                "MJML daily card photo for post {$post['message']->id} should link to the view URL."
            );
        }
    }

    /**
     * The MJML immediate hero photo href points at the VIEW url.
     */
    public function test_mjml_immediate_hero_photo_links_to_view_url(): void
    {
        $post  = $this->makePost(666, 'Offer');
        $posts = collect([$post]);

        $mjml = view(
            'emails.mjml.digest.unified',
            $this->templateData($posts, UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $quotedView    = preg_quote($post['viewUrl'], '/');
        $quotedDisplay = preg_quote($post['displayImageUrl'], '/');

        // MJML immediate hero: <mj-image href="VIEWURL" src="DISPLAY" .../>
        $this->assertMatchesRegularExpression(
            '/<mj-image\s+href="' . $quotedView . '"\s+src="' . $quotedDisplay . '"/s',
            $mjml,
            'MJML immediate hero photo should link to the view URL.'
        );
    }

    /**
     * The Reply controls must keep the reply CTA URL (?reply=1) even though the
     * photo/title now use the view URL — proving the two are different links and
     * that we did not accidentally strip the reply path while fixing the photo.
     */
    public function test_reply_controls_keep_reply_url_distinct_from_photo_view_url(): void
    {
        // Daily card: the Reply <a> button uses messageUrl; the photo uses viewUrl.
        $dailyPost = $this->makePost(777, 'Offer');
        $dailyMjml = view(
            'emails.mjml.digest.unified',
            $this->templateData(collect([$dailyPost, $this->makePost(778, 'Wanted')]), UnifiedDigestService::MODE_DAILY)
        )->render();

        $quotedReply = preg_quote($dailyPost['messageUrl'], '/');
        $this->assertMatchesRegularExpression(
            '/<a href="' . $quotedReply . '"[^>]*>Reply<\/a>/s',
            $dailyMjml,
            'MJML daily card Reply button must keep the reply CTA URL.'
        );
        $this->assertStringContainsString('href="' . $dailyPost['viewUrl'] . '"', $dailyMjml);
        $this->assertNotSame($dailyPost['viewUrl'], $dailyPost['messageUrl']);

        // Immediate: the Reply mj-button uses messageUrl; the hero uses viewUrl.
        $immPost = $this->makePost(779, 'Offer');
        $immMjml = view(
            'emails.mjml.digest.unified',
            $this->templateData(collect([$immPost]), UnifiedDigestService::MODE_IMMEDIATE)
        )->render();

        $quotedImmReply = preg_quote($immPost['messageUrl'], '/');
        $this->assertMatchesRegularExpression(
            '/<mj-button\s+href="' . $quotedImmReply . '"/s',
            $immMjml,
            'Immediate MJML Reply button must keep the reply CTA URL.'
        );
        $this->assertStringContainsString('href="' . $immPost['viewUrl'] . '"', $immMjml);
    }
}
