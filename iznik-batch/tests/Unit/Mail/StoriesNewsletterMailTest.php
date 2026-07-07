<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

/**
 * Preheader coverage for resources/views/emails/mjml/stories/newsletter.blade.php.
 *
 * The mj-preview now uses the first story headline so the inbox preview line
 * carries real content rather than the generic "Stories from other freeglers!"
 * fallback.  These tests render the Blade view directly and assert that the
 * <mj-preview> element in the generated MJML source contains the expected text.
 */
class StoriesNewsletterMailTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeStory(string $headline, string $story = 'Something wonderful happened.'): array
    {
        return [
            'headline'   => $headline,
            'story'      => $story,
            'username'   => 'Test Freegler',
            'groupname'  => 'Freegle Testown',
            'profileurl' => null,
            'photo'      => null,
        ];
    }

    /**
     * Render the newsletter Blade view with the given stories and return the
     * raw MJML source (before MJML→HTML compilation).
     */
    private function renderView(array $stories): string
    {
        return view('emails.mjml.stories.newsletter', [
            'stories'        => $stories,
            'headerImageUrl' => 'https://example.com/header.jpg',
            'tellUrl'        => 'https://www.ilovefreegle.org/stories',
            'giveUrl'        => 'https://www.ilovefreegle.org/give',
            'findUrl'        => 'https://www.ilovefreegle.org/find',
            'previewText'    => '',
            'email'          => 'reader@example.com',
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe',
            'settingsUrl'    => 'https://www.ilovefreegle.org/settings',
        ])->render();
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_preheader_contains_first_story_headline(): void
    {
        // The inbox preview must carry the headline of the first story so
        // recipients know what to expect before opening the email.
        $stories = [
            $this->makeStory('I gave my old piano a new home'),
            $this->makeStory('A wardrobe saved from landfill'),
        ];

        $html = $this->renderView($stories);

        $this->assertStringContainsString(
            'I gave my old piano a new home',
            $html,
            'mj-preview must include the first story headline'
        );
    }

    public function test_preheader_wraps_headline_in_curly_quotes_with_suffix(): void
    {
        // The full preview expression is: "\u{201c}" . Str::limit($headline, 70) . "\u{201d} and more freegler stories"
        $stories = [
            $this->makeStory('Rescued a sofa from the skip'),
        ];

        $html = $this->renderView($stories);

        $this->assertStringContainsString(
            "\u{201c}Rescued a sofa from the skip\u{201d} and more freegler stories",
            $html,
            'mj-preview must wrap the headline in curly quotes and append " and more freegler stories"'
        );
    }

    public function test_preheader_falls_back_to_static_text_when_no_stories(): void
    {
        // When the stories array is empty the ternary falls back to the static string.
        $html = $this->renderView([]);

        $this->assertStringContainsString(
            'Stories from other freeglers!',
            $html,
            'mj-preview must show the fallback text when there are no stories'
        );
    }

    public function test_preheader_truncates_long_headline_to_70_chars(): void
    {
        // Str::limit($headline, 70) appends "..." when the headline exceeds 70 characters.
        $longHeadline  = 'This is a very long headline that definitely exceeds seventy characters in total length here';
        $truncated     = \Illuminate\Support\Str::limit($longHeadline, 70);

        $stories = [$this->makeStory($longHeadline)];

        $html = $this->renderView($stories);

        // Isolate the preheader: the newsletter body legitimately renders the full
        // headline, so the truncation check must look only inside <mj-preview>.
        preg_match('/<mj-preview>(.*?)<\/mj-preview>/s', $html, $m);
        $preview = $m[1] ?? '';

        $this->assertStringContainsString(
            $truncated,
            $preview,
            'mj-preview must truncate long headlines to 70 characters'
        );
        $this->assertStringNotContainsString(
            $longHeadline,
            $preview,
            'mj-preview must not contain the un-truncated headline'
        );
    }
}
