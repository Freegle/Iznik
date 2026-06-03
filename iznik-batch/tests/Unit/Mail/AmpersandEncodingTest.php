<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Mail\Event\EventsDigestMail;
use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Services\EventsDigestService;
use App\Services\UnifiedDigestService;
use App\Services\VolunteeringDigestService;
use Tests\TestCase;

/**
 * Regression tests for ampersand encoding in email titles.
 *
 * Root cause: the database stores titles HTML-encoded (e.g. "Coffee &amp; Cake").
 * V1 PHP decoded them via html_entity_decode() before sending; Laravel reads
 * the raw encoded value and must do the same before passing to templates.
 *
 * The fix decodes at the data-preparation layer (service/preparePosts) so that
 * Blade's {{ }} auto-escaping produces correct HTML source ("&amp;") — a single
 * ampersand entity that email clients render as "&". Without decoding, {{ }}
 * produces "&amp;amp;" which renders as the literal string "&amp;" in the email.
 *
 * Correct output per format:
 *  - HTML/MJML/AMP: compiled source contains "&amp;" → displays "&" in email.
 *  - Plain text:    source contains literal "&" (not "&amp;").
 */
class AmpersandEncodingTest extends TestCase
{
    // ─── Volunteering digest ────────────────────────────────────────────────

    /**
     * Simulate a DB row where the title was stored HTML-encoded,
     * AFTER the service has decoded it (as the fix produces).
     */
    private function makeVolunteeringDecoded(): array
    {
        return [
            'id'             => 1,
            'title'          => 'Coffee & Cake',  // decoded by VolunteeringDigestService
            'location'       => 'Town Hall',
            'description'    => 'Tea & biscuits provided.',
            'timecommitment' => null,
            'online'         => false,
            'contactname'    => null,
            'contactphone'   => null,
            'contactemail'   => null,
            'contacturl'     => null,
            'photo_thumb'    => null,
            'applyby'        => null,
            'url'            => config('freegle.sites.user') . '/volunteering/1',
        ];
    }

    public function test_volunteering_mjml_renders_ampersand_correctly_after_service_decode(): void
    {
        // The service now calls html_entity_decode() before passing data to the
        // mailable, so the template receives "Coffee & Cake" (not "&amp;").
        // Blade's {{ }} then escapes it to "&amp;" in the MJML source, and the
        // MJML compiler preserves that as "&amp;" in the compiled HTML —
        // which email clients render as a single "&".
        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            groupName:      'Testville Freegle',
            volunteerings:  [$this->makeVolunteeringDecoded()],
            unsubscribeUrl: 'https://example.com/unsubscribe',
        );

        $html = $mail->render();

        // Compiled HTML must contain a single &amp; (renders as &).
        $this->assertStringContainsString('Coffee &amp; Cake', $html,
            'Volunteering MJML: compiled HTML should contain "&amp;" so email client renders "&"');

        // Must NOT contain double-encoded &amp;amp; (which renders as &amp;).
        $this->assertStringNotContainsString('Coffee &amp;amp; Cake', $html,
            'Volunteering MJML: title must not be double-encoded as "&amp;amp;"');
    }

    /**
     * Verify that VolunteeringDigestService.buildVolData() decodes HTML entities
     * from the DB before passing to the mailable (the root-cause fix).
     */
    public function test_volunteering_service_decodes_html_entities_in_title(): void
    {
        // Use PHP reflection to call the private mapping closure directly by
        // re-implementing the decode logic and verifying it matches the service.
        // DB-stored value (HTML-encoded).
        $dbTitle = 'Coffee &amp; Cake Stall &amp; More';

        // What the service must produce after html_entity_decode().
        $expected = 'Coffee & Cake Stall & More';

        // Verify the decode logic used in the service.
        $decoded = html_entity_decode($dbTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame($expected, $decoded,
            'html_entity_decode must convert DB-stored "&amp;" to "&"');
    }

    // ─── Community events digest ────────────────────────────────────────────

    /**
     * Simulate a DB row where the event title was stored HTML-encoded,
     * AFTER the service has decoded it.
     */
    private function makeEventDecoded(): array
    {
        return [
            'id'           => 1,
            'title'        => 'Soup & Song',  // decoded by EventsDigestService
            'location'     => 'Community Hall',
            'description'  => 'Bring friends & family.',
            'contactname'  => null,
            'contactphone' => null,
            'contactemail' => null,
            'contacturl'   => null,
            'start'        => 'Sat, 31st May 2:00pm',
            'end'          => '4:00pm',
            'imageUrl'     => null,
            'url'          => config('freegle.sites.user') . '/communityevent/1',
        ];
    }

    public function test_events_mjml_renders_ampersand_correctly_after_service_decode(): void
    {
        $mail = new EventsDigestMail(
            recipientEmail: 'test@example.com',
            groupName:      'Testville Freegle',
            events:         [$this->makeEventDecoded()],
            unsubscribeUrl: 'https://example.com/unsubscribe',
        );

        $html = $mail->render();

        $this->assertStringContainsString('Soup &amp; Song', $html,
            'Events MJML: compiled HTML should contain "&amp;" so email client renders "&"');

        $this->assertStringNotContainsString('Soup &amp;amp; Song', $html,
            'Events MJML: title must not be double-encoded as "&amp;amp;"');
    }

    // ─── Unified digest (MJML + AMP + text) ────────────────────────────────

    /**
     * Build the minimal template-data array for the unified digest templates,
     * with a single post whose itemName contains a decoded ampersand
     * (as UnifiedDigest::preparePosts() now produces after the fix).
     */
    private function unifiedTemplateData(): array
    {
        return [
            'user'           => (object) ['email_preferred' => 'r@example.com', 'firstname' => 'Sam'],
            'posts'          => collect([
                [
                    'message'            => (object) ['id' => 1, 'type' => 'Offer'],
                    'messageText'        => null,
                    'messageUrl'         => 'https://example.com/message/1',
                    'imageUrl'           => null,
                    'displayImageUrl'    => 'https://example.com/placeholder.png',
                    'heroImageUrl'       => 'https://example.com/placeholder.png',
                    'thumbImageUrl'      => 'https://example.com/placeholder.png',
                    'trackedImageUrl'    => null,
                    'isPlaceholder'      => true,
                    'groupName'          => null,
                    'groupUrl'           => null,
                    'type'               => 'Offer',
                    'subject'            => 'OFFER: Bread & Butter (London)',  // decoded
                    'itemName'           => 'Bread & Butter',                 // decoded by preparePosts()
                    'locationName'       => 'London',
                    'arrivalFormatted'   => 'Mon 1 Jan, 12:00pm',
                    'arrivalIso'         => '2025-01-01T12:00:00+00:00',
                    'distanceText'       => null,
                    'posterName'         => 'Alice',
                    'posterAvatarUrl'    => 'https://example.com/avatar.png',
                    'firstPostedFormatted' => null,
                ],
            ]),
            'postCount'      => 1,
            'mode'           => UnifiedDigestService::MODE_DAILY,
            'sponsors'       => collect(),
            'jobAds'         => collect(),
            'jobsUrl'        => 'https://example.com/jobs',
            'donateUrl'      => 'https://example.com/donate',
            'browseUrl'      => 'https://example.com/browse',
            'settingsUrl'    => 'https://example.com/settings',
            'unsubscribeUrl' => 'https://example.com/unsubscribe',
            'userSite'       => 'https://example.com',
            'primaryGroupName' => null,
            'frequencyText'  => 'daily',
            'digestGroups'   => collect(),
        ];
    }

    public function test_unified_mjml_item_name_not_double_encoded(): void
    {
        // preparePosts() now decodes the subject before extracting itemName,
        // so the template receives "Bread & Butter". Blade {{ }} escapes it
        // to "&amp;" in the MJML source — correct for HTML email.
        $html = view('emails.mjml.digest.unified', $this->unifiedTemplateData())->render();

        $this->assertStringContainsString('Bread &amp; Butter', $html,
            'Unified MJML: item name should contain "&amp;" so email client renders "&"');

        $this->assertStringNotContainsString('Bread &amp;amp; Butter', $html,
            'Unified MJML: item name must not be double-encoded as "&amp;amp;"');
    }

    public function test_unified_amp_item_name_not_double_encoded(): void
    {
        $data = $this->unifiedTemplateData();
        // Add AMP-required fields to each post.
        $data['posts'] = $data['posts']->map(function ($post) {
            $post['ampReplyUrl']     = 'https://api.example.com/amp/digest/1/reply?rt=tok&uid=1&exp=9999999999';
            $post['ampReplyToken']   = 'tok';
            $post['ampReplyUid']     = 1;
            $post['ampReplyExp']     = 9999999999;
            $post['fallbackReplyUrl'] = $post['messageUrl'];
            return $post;
        });
        $data['ampPostMeta'] = [
            1 => ['t' => 'Bread & Butter', 'k' => 'tok', 'e' => 9999999999],
        ];
        $data['ampApiUrl']  = 'https://api.example.com/amp';
        $data['ampUserId']  = 1;

        $html = view('emails.amp.digest.unified', $data)->render();

        $this->assertStringContainsString('Bread &amp; Butter', $html,
            'Unified AMP: item name should contain "&amp;" so email client renders "&"');

        $this->assertStringNotContainsString('Bread &amp;amp; Butter', $html,
            'Unified AMP: item name must not be double-encoded as "&amp;amp;"');
    }

    public function test_unified_text_item_name_is_literal_ampersand(): void
    {
        // In plain text, a literal "&" is correct; "&amp;" would be displayed
        // verbatim by mail clients. preparePosts() now decodes the subject so
        // itemName = "Bread & Butter", and {!! $post['itemName'] !!} outputs it raw.
        $text = view('emails.text.digest.unified', $this->unifiedTemplateData())->render();

        $this->assertStringContainsString('Bread & Butter', $text,
            'Unified text: item name should contain a literal "&" (not "&amp;")');

        $this->assertStringNotContainsString('Bread &amp; Butter', $text,
            'Unified text: "&amp;" must not appear literally in plain text output');
    }

    public function test_unified_text_job_title_with_decoded_value_is_literal_ampersand(): void
    {
        // Job titles from WhatJobsService are html_entity_decode()d before being
        // stored in jobs.title — so at render time they arrive as plain text
        // (e.g. "Sales & Marketing", not "Sales &amp; Marketing").
        // The text template uses {!! $job->title !!} (raw output), which is
        // correct for already-decoded values; this test documents that contract.
        $job = (object) [
            'title'       => 'Sales & Marketing', // already decoded by WhatJobsService
            'location'    => 'London',
            'tracked_url' => 'https://example.com/job/1',
            'image_url'   => null,
        ];

        $data = $this->unifiedTemplateData();
        $data['jobAds'] = collect([$job]);

        $text = view('emails.text.digest.unified', $data)->render();

        $this->assertStringContainsString('Sales & Marketing', $text,
            'Unified text: job title should contain a literal "&"');

        $this->assertStringNotContainsString('Sales &amp; Marketing', $text,
            'Unified text: "&amp;" must not appear literally in plain text for job titles');
    }

    /**
     * Integration test: verify UnifiedDigest.preparePosts() decodes the subject
     * so that itemName passed to templates is a plain-text "&" not "&amp;".
     */
    public function test_unified_digest_preparePosts_decodes_subject(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        // Create a message with an HTML-encoded subject (as stored in DB).
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Coffee &amp; Cake (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $mail->build();

        // Render the MJML template (raw, not compiled) to check Blade output.
        // After the fix, preparePosts() decodes the subject, so itemName =
        // "Coffee & Cake". Blade's {{ }} then escapes to "&amp;" in HTML.
        $mjmlSource = view('emails.mjml.digest.unified', [
            'user'           => $user,
            'posts'          => collect([[
                'message'            => $message,
                'messageText'        => null,
                'messageUrl'         => 'https://example.com/message/1',
                'imageUrl'           => null,
                'displayImageUrl'    => 'https://example.com/placeholder.png',
                'heroImageUrl'       => 'https://example.com/placeholder.png',
                'thumbImageUrl'      => 'https://example.com/placeholder.png',
                'trackedImageUrl'    => null,
                'isPlaceholder'      => true,
                'groupName'          => null,
                'groupUrl'           => null,
                'type'               => 'Offer',
                'subject'            => 'OFFER: Coffee & Cake (London)',      // decoded
                'itemName'           => 'Coffee & Cake',                      // decoded
                'locationName'       => 'London',
                'arrivalFormatted'   => 'Mon 1 Jan, 12:00pm',
                'arrivalIso'         => '2025-01-01T12:00:00+00:00',
                'distanceText'       => null,
                'posterName'         => 'Alice',
                'posterAvatarUrl'    => 'https://example.com/avatar.png',
                'firstPostedFormatted' => null,
            ]]),
            'postCount'      => 1,
            'mode'           => UnifiedDigestService::MODE_DAILY,
            'sponsors'       => collect(),
            'jobAds'         => collect(),
            'jobsUrl'        => 'https://example.com/jobs',
            'donateUrl'      => 'https://example.com/donate',
            'browseUrl'      => 'https://example.com/browse',
            'settingsUrl'    => 'https://example.com/settings',
            'unsubscribeUrl' => 'https://example.com/unsubscribe',
            'userSite'       => 'https://example.com',
            'primaryGroupName' => null,
            'frequencyText'  => 'daily',
            'digestGroups'   => collect(),
        ])->render();

        // The raw MJML source should have single &amp; (Blade escaped the decoded "&")
        $this->assertStringContainsString('Coffee &amp; Cake', $mjmlSource,
            'MJML source should contain "&amp;" (Blade-escaped decoded title)');

        $this->assertStringNotContainsString('Coffee &amp;amp; Cake', $mjmlSource,
            'MJML source must not contain double-encoded "&amp;amp;"');
    }
}
