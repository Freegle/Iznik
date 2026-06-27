<?php

namespace Tests\Unit\Mail\Newsfeed;

use Tests\TestCase;

/**
 * Unit tests for the newsfeed ("chitchat") digest email preheader.
 *
 * The mj-preview was updated to show a snippet of the first item's text instead
 * of the generic "N conversation(s) from your neighbours", so recipients can see
 * at a glance what is being discussed before opening the email.
 */
class NewsfeedDigestMailTest extends TestCase
{
    private function makeItems(array $overrides = []): array
    {
        return array_merge([
            [
                'type'    => 'Message',
                'text'    => 'Has anyone tried the new bakery on the high street?',
                'author'  => 'Alice',
                'replies' => [],
            ],
        ], $overrides);
    }

    public function test_preheader_contains_first_item_text(): void
    {
        // The mj-preview should show the first chitchat post's text so recipients
        // know immediately what is being discussed without opening the email.
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        $items = $this->makeItems();

        $html = view('emails.mjml.newsfeed.digest', [
            'items'       => $items,
            'count'       => count($items),
            'readUrl'     => $userSite . '/chitchat?src=newsfeeddigest',
            'settingsUrl' => $userSite . '/settings?src=newsfeeddigest',
            'userSite'    => $userSite,
            'email'       => 'recipient@example.com',
        ])->render();

        $this->assertStringContainsString(
            'Has anyone tried the new bakery on the high street?',
            $html,
            'Preheader must include the first item\'s text'
        );
    }

    public function test_preheader_shows_and_more_suffix_for_multiple_items(): void
    {
        // When there is more than one chitchat post, the preheader should append
        // " - and N more" so the recipient knows additional posts are included.
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        $items = [
            [
                'type'    => 'Message',
                'text'    => 'Does anyone have a spare bicycle pump?',
                'author'  => 'Bob',
                'replies' => [],
            ],
            [
                'type'    => 'Message',
                'text'    => 'Community litter pick this Saturday at 10am',
                'author'  => 'Carol',
                'replies' => [],
            ],
            [
                'type'    => 'Message',
                'text'    => 'Lost cat spotted near the park — ginger tabby',
                'author'  => 'Dave',
                'replies' => [],
            ],
        ];

        $html = view('emails.mjml.newsfeed.digest', [
            'items'       => $items,
            'count'       => count($items),
            'readUrl'     => $userSite . '/chitchat?src=newsfeeddigest',
            'settingsUrl' => $userSite . '/settings?src=newsfeeddigest',
            'userSite'    => $userSite,
            'email'       => 'recipient@example.com',
        ])->render();

        // First item text must appear in the preview.
        $this->assertStringContainsString('Does anyone have a spare bicycle pump?', $html);
        // The " - and 2 more" suffix must be appended for the remaining two items.
        $this->assertStringContainsString('- and 2 more', $html);
    }

    public function test_preheader_has_no_and_more_suffix_for_single_item(): void
    {
        // A single-item digest must not produce a "- and 0 more" artefact.
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        $items = $this->makeItems();

        $html = view('emails.mjml.newsfeed.digest', [
            'items'       => $items,
            'count'       => count($items),
            'readUrl'     => $userSite . '/chitchat?src=newsfeeddigest',
            'settingsUrl' => $userSite . '/settings?src=newsfeeddigest',
            'userSite'    => $userSite,
            'email'       => 'recipient@example.com',
        ])->render();

        $this->assertStringNotContainsString('- and 0 more', $html);
        $this->assertStringNotContainsString('and 0 more', $html);
    }

    public function test_preheader_truncates_long_item_text(): void
    {
        // Str::limit($text, 80) is applied in the template, so texts longer than
        // 80 characters should be truncated with "..." in the preheader.
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        $longText = 'This is a very long chitchat post that goes well beyond eighty characters and should therefore be trimmed in the inbox preview so it does not overflow';

        $html = view('emails.mjml.newsfeed.digest', [
            'items'       => [
                [
                    'type'    => 'Message',
                    'text'    => $longText,
                    'author'  => 'Eve',
                    'replies' => [],
                ],
            ],
            'count'       => 1,
            'readUrl'     => $userSite . '/chitchat?src=newsfeeddigest',
            'settingsUrl' => $userSite . '/settings?src=newsfeeddigest',
            'userSite'    => $userSite,
            'email'       => 'recipient@example.com',
        ])->render();

        // The raw long text must not appear verbatim inside the mj-preview tag.
        $this->assertStringNotContainsString(
            '<mj-preview>' . $longText . '</mj-preview>',
            $html
        );
        // The truncation marker must appear in the preview.
        $this->assertMatchesRegularExpression('/<mj-preview>.*\.\.\.<\/mj-preview>/', $html);
    }
}
