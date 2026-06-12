<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\DigestStyle;
use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
use Illuminate\Mail\Mailable;
use ReflectionProperty;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * The collapsible "In this digest" summary index at the top of the daily
 * unified digest.
 *
 * Behaviour under test (one line per post, linking to the post's page on the
 * website — never an in-email #anchor):
 *  - daily digests render a summary index; immediate single-post digests don't;
 *  - the first DigestStyle::SUMMARY_VISIBLE_LINES lines are always visible and
 *    the rest are tucked behind a collapsible "Show N more" control;
 *  - HTML uses <details>/<summary>; AMP uses <amp-accordion>; text falls back
 *    to "…and N more below";
 *  - every summary link points at {site}/message/{id}, not "#...".
 */
class UnifiedDigestSummaryTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function spoolAndLoad(Mailable $mailable, string $recipient): array
    {
        $id = $this->spooler->spool($mailable, $recipient);
        $path = $this->testSpoolDir.'/pending/'.$id.'.json';

        return json_decode(file_get_contents($path), true) ?? [];
    }

    /**
     * Build a daily/immediate digest with $count real posts whose item names
     * are distinct words so they can be located in rendered output.
     *
     * @return array{0: UnifiedDigest, 1: array<int,\App\Models\Message>}
     */
    private function buildDigest(int $count, string $mode): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $words = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf'];
        $messages = [];
        $posts = collect();
        for ($i = 0; $i < $count; $i++) {
            $m = $this->createTestMessage($poster, $group, [
                'subject' => 'OFFER: '.$words[$i].' (TestLocation)',
            ]);
            $messages[] = $m;
            $posts->push(['message' => $m, 'postedToGroups' => [$group->id]]);
        }

        return [new UnifiedDigest($user, $posts, $mode), $messages];
    }

    /**
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function preparedPostsOf(UnifiedDigest $mail): \Illuminate\Support\Collection
    {
        $ref = new ReflectionProperty(UnifiedDigest::class, 'preparedPosts');
        $ref->setAccessible(true);

        return $ref->getValue($mail);
    }

    private static function decodeTrackedTarget(string $url): string
    {
        if (preg_match('/[?&]url=([^&]+)/', $url, $m)) {
            return base64_decode(urldecode($m[1])) ?: $url;
        }

        return $url;
    }

    /**
     * Fabricated template data with $count posts carrying every key the AMP
     * and text blades reference, so the views can render standalone (the MJML
     * variant is exercised through the real build+spool path instead).
     */
    private function summaryViewData(int $count): array
    {
        $words = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf'];
        $posts = collect();
        for ($i = 0; $i < $count; $i++) {
            $posts->push([
                'message' => (object) ['id' => 1000 + $i],
                'type' => $i % 2 === 0 ? 'Offer' : 'Wanted',
                'subject' => 'OFFER: '.$words[$i].' (TestLocation)',
                'itemName' => $words[$i],
                'locationName' => 'TestLocation',
                'messageText' => 'Body text for '.$words[$i],
                'messageUrl' => 'https://example.com/message/'.(1000 + $i).'?reply=1',
                'summaryUrl' => 'https://example.com/message/'.(1000 + $i),
                'fallbackReplyUrl' => 'https://example.com/message/'.(1000 + $i).'?reply=1',
                'ampReplyUrl' => 'https://api.example.com/amp/digest/'.(1000 + $i).'/reply',
                'distanceText' => '2 miles',
                'arrivalFormatted' => 'Mon 1 Jun, 9:00am',
                'posterName' => 'Sam',
                'posterAvatarUrl' => 'https://example.com/avatar.png',
                'groupName' => 'Test Group',
                'groupUrl' => 'https://example.com/explore/testgroup',
                'firstPostedFormatted' => null,
                'thumbImageUrl' => 'https://example.com/thumb.png',
                'heroImageUrl' => 'https://example.com/hero.png',
                'displayImageUrl' => 'https://example.com/img.png',
                'isPlaceholder' => false,
            ]);
        }

        return [
            'user' => (object) ['email_preferred' => 'r@example.com', 'displayname' => 'Sam'],
            'posts' => $posts,
            'postCount' => $count,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'isSingle' => false,
            'sponsors' => collect(),
            'jobAds' => collect(),
            'completedPosts' => collect(),
            'jobsUrl' => 'https://example.com/jobs',
            'donateUrl' => 'https://example.com/donate',
            'browseUrl' => 'https://example.com/browse',
            'settingsUrl' => 'https://example.com/settings',
            'unsubscribeUrl' => 'https://example.com/unsubscribe',
            'userSite' => 'https://example.com',
            'siteName' => 'Freegle',
        ];
    }

    // ── preparePosts: website summary URL ───────────────────────────────────

    public function test_prepared_posts_carry_summary_url_to_website_message_page(): void
    {
        [$mail, $messages] = $this->buildDigest(1, UnifiedDigestService::MODE_DAILY);
        $card = $this->preparedPostsOf($mail)->first();

        $this->assertArrayHasKey('summaryUrl', $card);
        $target = self::decodeTrackedTarget($card['summaryUrl']);

        // Points at the post's page on the website…
        $this->assertStringContainsString('/message/'.$messages[0]->id, $target);
        // …and is NOT an in-email anchor (the V1 inconsistency we're dropping).
        $this->assertStringNotContainsString('#', $target);
        // The summary link is its own jump-to-post link, not the reply CTA.
        $this->assertStringNotContainsString('reply=1', $target);
    }

    // ── HTML (MJML → <details>) ─────────────────────────────────────────────

    public function test_html_daily_summary_collapses_beyond_three(): void
    {
        [$mail] = $this->buildDigest(5, UnifiedDigestService::MODE_DAILY);
        $prepared = $this->preparedPostsOf($mail);
        // Each summary line is the full subject; the first 3 subjects appear
        // only in the summary index (cards show the bare item name), so they
        // uniquely locate the summary section.
        $subjects = $prepared->pluck('subject')->all();

        $html = $this->spoolAndLoad($mail, 'r@example.com')['html'] ?? '';

        $this->assertStringContainsString('In this digest', $html);
        $this->assertStringContainsString('<details', $html);
        // 5 posts − 3 visible = 2 hidden.
        $this->assertStringContainsString('Show 2 more', $html);

        // The summary links are the website summary URLs (Blade escapes & to
        // &amp; in the href, so compare against the escaped form).
        $escapedFirstUrl = htmlspecialchars($prepared->first()['summaryUrl'], ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString($escapedFirstUrl, $html);

        // Every post's subject is listed in the summary.
        foreach ($subjects as $s) {
            $this->assertStringContainsString($s, $html);
        }

        // First 3 lines sit before the collapse; the remaining 2 inside it.
        $pos = strpos($html, '<details');
        $this->assertNotFalse($pos);
        $this->assertLessThan($pos, strpos($html, $subjects[0]));
        $this->assertLessThan($pos, strpos($html, $subjects[1]));
        $this->assertLessThan($pos, strpos($html, $subjects[2]));
        $this->assertGreaterThan($pos, strpos($html, $subjects[3]));
        $this->assertGreaterThan($pos, strpos($html, $subjects[4]));
    }

    public function test_html_daily_three_posts_show_summary_without_collapse(): void
    {
        [$mail] = $this->buildDigest(3, UnifiedDigestService::MODE_DAILY);
        $prepared = $this->preparedPostsOf($mail);
        $subjects = $prepared->pluck('subject')->all();

        $html = $this->spoolAndLoad($mail, 'r@example.com')['html'] ?? '';

        $this->assertStringContainsString('In this digest', $html);
        // Summary links use the website summary URL (escaped & → &amp;).
        $escapedFirstUrl = htmlspecialchars($prepared->first()['summaryUrl'], ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString($escapedFirstUrl, $html);
        foreach ($subjects as $s) {
            $this->assertStringContainsString($s, $html);
        }
        // No overflow → no collapsible control ("Show N more" only ever
        // appears inside the <details>, so its absence proves no collapse).
        $this->assertStringNotContainsString('<details', $html);
    }

    public function test_html_immediate_single_post_has_no_summary_index(): void
    {
        [$mail] = $this->buildDigest(1, UnifiedDigestService::MODE_IMMEDIATE);

        $html = $this->spoolAndLoad($mail, 'r@example.com')['html'] ?? '';

        $this->assertStringNotContainsString('In this digest', $html);
    }

    // ── AMP (<amp-accordion>) ───────────────────────────────────────────────

    public function test_amp_summary_uses_accordion_for_overflow_with_website_links(): void
    {
        $html = view('emails.amp.digest.unified', $this->summaryViewData(5))->render();

        $this->assertStringContainsString('In this digest', $html);
        // Native collapsible: a dedicated summary accordion (the per-post reply
        // accordion uses class="reply-acc", so this element is unambiguous).
        $this->assertStringContainsString('<amp-accordion class="summary-acc"', $html);
        $this->assertStringContainsString('Show 2 more', $html);

        // Each summary link points at the website message page.
        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString('https://example.com/message/'.(1000 + $i), $html);
        }
        // AMP4Email forbids fragment hrefs; assert we never emit one anywhere.
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function test_amp_summary_no_accordion_when_three_or_fewer(): void
    {
        $html = view('emails.amp.digest.unified', $this->summaryViewData(3))->render();

        $this->assertStringContainsString('In this digest', $html);
        // The summary's own accordion is absent (per-post reply accordions,
        // which use class="reply-acc", may still render). Check the rendered
        // "Show N more" span markup, not the literal "Show " (which also
        // appears in a CSS comment that is not stripped from <style>).
        $this->assertStringNotContainsString('<amp-accordion class="summary-acc"', $html);
        $this->assertStringNotContainsString('summary-more">', $html);
    }

    // ── Text (…and N more below) ────────────────────────────────────────────

    public function test_text_summary_lists_first_three_with_more_note(): void
    {
        $text = view('emails.text.digest.unified', $this->summaryViewData(5))->render();

        $this->assertStringContainsString('In this digest', $text);
        $this->assertStringContainsString('Alpha', $text);
        $this->assertStringContainsString('Bravo', $text);
        $this->assertStringContainsString('Charlie', $text);
        $this->assertStringContainsString('https://example.com/message/1000', $text);
        // 5 − 3 = 2 more posts below.
        $this->assertStringContainsString('2 more', $text);
    }

    public function test_summary_visible_lines_constant_is_three(): void
    {
        $this->assertSame(3, DigestStyle::SUMMARY_VISIBLE_LINES);
    }
}
