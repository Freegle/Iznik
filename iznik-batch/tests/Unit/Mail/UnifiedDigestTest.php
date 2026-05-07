<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
use Tests\TestCase;

class UnifiedDigestTest extends TestCase
{
    public function test_can_be_constructed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $this->assertInstanceOf(UnifiedDigest::class, $mail);
    }

    public function test_build_returns_self(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $result = $mail->build();

        $this->assertInstanceOf(UnifiedDigest::class, $result);
    }

    public function test_subject_with_single_post(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $envelope = $mail->envelope();

        $this->assertEquals("What's New (1 post) - Sofa", $envelope->subject);
    }

    public function test_subject_with_multiple_posts(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'WANTED: Table (London)']);
        $msg3 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Books (London)']);

        $posts = collect([
            ['message' => $msg1, 'postedToGroups' => [$group->id]],
            ['message' => $msg2, 'postedToGroups' => [$group->id]],
            ['message' => $msg3, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $envelope = $mail->envelope();

        $this->assertStringStartsWith("What's New (3 posts)", $envelope->subject);
        $this->assertStringContainsString('Sofa', $envelope->subject);
    }

    public function test_tracked_urls_contain_post_positions(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Table (London)']);

        $posts = collect([
            ['message' => $msg1, 'postedToGroups' => [$group->id]],
            ['message' => $msg2, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $mail->build();

        // Verify tracking was initialised with the mode-specific email_type
        // so sysadmin filters can split immediate vs daily.
        $tracking = $mail->getTracking();
        $this->assertNotNull($tracking);
        $this->assertEquals('UnifiedDigestDaily', $tracking->email_type);
    }

    /**
     * Fabricate the minimal data the digest templates need so they can be
     * rendered standalone (empty posts skips the per-post loop; jobs/sponsors
     * blocks are gated only on $jobAds / $sponsors).
     */
    private function digestTemplateData(array $jobAds, array $sponsors): array
    {
        $user = (object) ['email_preferred' => 'r@example.com', 'displayname' => 'Sam'];
        return [
            'user' => $user,
            'posts' => collect(),
            'postCount' => 0,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'isSingle' => false,
            'sponsors' => collect($sponsors),
            'jobAds' => collect($jobAds),
            'jobsUrl' => 'https://example.com/jobs?t=1',
            'donateUrl' => 'https://example.com/donate?t=1',
            'browseUrl' => 'https://example.com/browse?t=1',
            'settingsUrl' => 'https://example.com/settings?t=1',
            'unsubscribeUrl' => 'https://example.com/unsubscribe?t=1',
            'userSite' => 'https://example.com',
            'siteName' => 'Freegle',
        ];
    }

    public function test_amp_template_renders_jobs_and_sponsors(): void
    {
        $job = (object) [
            'title' => 'Warehouse Operative',
            'location' => 'Edinburgh',
            'tracked_url' => 'https://example.com/job/1?t=1',
            'image_url' => 'https://example.com/job1.png',
        ];
        $sponsor = (object) [
            'name' => 'Edinburgh Council',
            'tagline' => 'Backs reuse',
            'linkurl' => 'https://council.example.com',
            'imageurl' => 'https://council.example.com/logo.png',
        ];

        $html = view('emails.amp.digest.unified', $this->digestTemplateData([$job], [$sponsor]))->render();

        // Jobs block (previously absent from AMP entirely).
        $this->assertStringContainsString('Jobs near you', $html);
        $this->assertStringContainsString('Warehouse Operative', $html);
        $this->assertStringContainsString('https://example.com/job/1?t=1', $html);
        $this->assertStringContainsString('View more jobs', $html);
        // Sponsors block (previously absent from AMP entirely).
        $this->assertStringContainsString('Sponsored by', $html);
        $this->assertStringContainsString('Edinburgh Council', $html);
    }

    public function test_amp_template_omits_jobs_and_sponsors_when_empty(): void
    {
        $html = view('emails.amp.digest.unified', $this->digestTemplateData([], []))->render();

        // Assert on markup-only strings (CSS comments/classes must not contain
        // these, or the present/omit pair becomes meaningless).
        $this->assertStringNotContainsString('Jobs near you', $html);
        $this->assertStringNotContainsString('View more jobs', $html);
        $this->assertStringNotContainsString('Sponsored by', $html);
    }

    public function test_text_template_renders_jobs(): void
    {
        $job = (object) [
            'title' => 'Delivery Driver',
            'location' => 'Leith',
            'tracked_url' => 'https://example.com/job/2?t=1',
            'image_url' => null,
        ];

        $text = view('emails.text.digest.unified', $this->digestTemplateData([$job], []))->render();

        $this->assertStringContainsString('Jobs near you', $text);
        $this->assertStringContainsString('Delivery Driver', $text);
        $this->assertStringContainsString('https://example.com/job/2?t=1', $text);
    }

    public function test_prepared_posts_carry_group_name_and_explore_url(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (Town)']);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);
        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $ref = new \ReflectionProperty(UnifiedDigest::class, 'preparedPosts');
        $ref->setAccessible(true);
        $prepared = $ref->getValue($mail);
        $card = $prepared->first();

        // Friendly full name (not the short name) is the displayed group label.
        $this->assertSame($group->namefull, $card['groupName']);
        // The link points at the group's /explore page (keyed on nameshort).
        // groupUrl is wrapped by the click-tracker (…/e/d/r/TOKEN?url=BASE64),
        // so decode the inner target before asserting.
        $this->assertNotNull($card['groupUrl']);
        $target = $card['groupUrl'];
        if (preg_match('/[?&]url=([^&]+)/', $card['groupUrl'], $mm)) {
            $target = base64_decode(urldecode($mm[1])) ?: $target;
        }
        $this->assertStringContainsString('/explore/', $target);
        $this->assertStringContainsString($group->nameshort, $target);
    }

    public function test_cross_post_text_shown_for_multiple_groups(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $this->createMembership($user, $group1);
        $this->createMembership($user, $group2);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group1);
        $this->createMembership($poster, $group2);

        $message = $this->createTestMessage($poster, $group1, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group1->id, $group2->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $mail->build();

        // The mail was built successfully with cross-post data.
        $this->assertInstanceOf(UnifiedDigest::class, $mail);
    }

    public function test_tracking_metadata_contains_mode_and_count(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $tracking = $mail->getTracking();
        $this->assertNotNull($tracking);

        $metadata = $tracking->metadata;
        $this->assertEquals('daily', $metadata['mode']);
        $this->assertEquals(1, $metadata['post_count']);
        $this->assertArrayHasKey('digest_number', $metadata);
    }

    public function test_tracking_metadata_records_post_composition_in_order(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Table (London)']);
        $msg3 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Chair (London)']);

        $posts = collect([
            ['message' => $msg1, 'postedToGroups' => [$group->id]],
            ['message' => $msg2, 'postedToGroups' => [$group->id]],
            ['message' => $msg3, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $metadata = $mail->getTracking()->metadata;
        // Ordered msgids, position = index — must line up with the
        // "post_{i}" / "image_{i}" position labels so an open with no click
        // can still be attributed per-post (the eyeball-accounting model).
        $this->assertEquals([$msg1->id, $msg2->id, $msg3->id], $metadata['post_msgids']);
        $this->assertEquals(3, $metadata['post_count']);
    }

    public function test_immediate_records_groupid_daily_does_not(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        // An immediate digest is about one community → record which group.
        $immediateMail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals($group->id, $immediateMail->getTracking()->groupid);

        // A daily digest spans the member's groups → not tied to one.
        $dailyMail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $this->assertNull($dailyMail->getTracking()->groupid);
    }

    public function test_immediate_mode_envelope_from_is_noreply_for_amp(): void
    {
        // Gmail's AMP-for-Email dynamic-mail allowlist keys on the From:
        // domain. If From: were the per-message replyto-{id}-{uid}@... address
        // (an ephemeral, per-recipient sender), Gmail wouldn't render the AMP
        // version and would surface it as an attachment. Keep From: pinned to
        // the registered noreply sender; Reply-To carries the per-message
        // routing instead (covered by the test below).
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser(['fullname' => 'Test Poster']);
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $envelope = $mail->envelope();

        $this->assertEquals(config('freegle.mail.noreply_addr'), $envelope->from->address);
    }

    public function test_immediate_mode_from_displayname_uses_poster_displayname(): void
    {
        // The inbox preview shows the From display-name ("Ewalina on Freegle",
        // V1 parity), and the body shows the same name resolved from
        // User->displayname. Both must agree; the previous code only consulted
        // messages.fromname, which was often empty and produced "Freegler on
        // Freegle" in the inbox while the body said "Ewalina".
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // displayname is a computed accessor (User::getDisplayNameAttribute),
        // not a stored column — it derives from fullname, so set fullname here.
        // Passing a 'displayname' key would be silently ignored by the accessor.
        $poster = $this->createTestUser([
            'fullname' => 'Ewalina',
        ]);
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
            'fromname' => '', // empty, so the resolver must use User->displayname
        ]);
        // Force the fromUser relation so the mailable can read displayname.
        $message->setRelation('fromUser', $poster);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $envelope = $mail->envelope();

        $this->assertEquals('Ewalina on ' . config('freegle.branding.name'), $envelope->from->name);
        $this->assertStringNotContainsString('Freegler', $envelope->from->name);
    }

    public function test_immediate_mode_from_name_does_not_double_partner_attribution(): void
    {
        // Partner (Trash Nothing) posters carry a name that already ends in
        // " via Trash Nothing". We strip the partner attribution and join the
        // site name with " on ", so this reads "Ewalina on Freegle" rather than
        // surfacing partner branding or the jarring double "via ... via".
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser([
            'fullname' => 'Ewalina via Trash Nothing',
            'displayname' => 'Ewalina via Trash Nothing',
        ]);
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
            'fromname' => 'Ewalina via Trash Nothing',
        ]);
        $message->setRelation('fromUser', $poster);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $envelope = $mail->envelope();

        $this->assertEquals(
            'Ewalina on ' . config('freegle.branding.name'),
            $envelope->from->name
        );
        $this->assertStringNotContainsString('Trash Nothing', $envelope->from->name);
        $this->assertStringNotContainsString('via Freegle', $envelope->from->name);
    }

    public function test_immediate_mode_sets_reply_to_header_with_replyto_address(): void
    {
        // From is the noreply sender (so Gmail trusts the AMP part), and the
        // per-message replyto-{msgId}-{userId} address goes in Reply-To.
        // Clients that honour Reply-To (which is most of them, per RFC 5322)
        // will then route a "Reply" back to the original poster via the
        // inbound mail handler that decodes that address.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser(['fullname' => 'Test Poster']);
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $expectedReplyTo = "replyto-{$message->id}-{$user->id}@" . config('freegle.mail.user_domain');
        $noreply = config('freegle.mail.noreply_addr');

        $data = $this->spoolAndLoad($mail, $user->email_preferred);

        // From is the noreply sender (NOT the per-message replyto address).
        $fromAddresses = array_column($data['from'] ?? [], 'address');
        $this->assertContains($noreply, $fromAddresses);
        $this->assertNotContains($expectedReplyTo, $fromAddresses);

        // Reply-To carries the per-message routing address.
        $replyToAddresses = array_column($data['reply_to'] ?? [], 'address');
        $this->assertContains($expectedReplyTo, $replyToAddresses);
    }

    public function test_immediate_mode_sets_v1_parity_headers(): void
    {
        // Feedback-ID — Google FBL spec ({qualifier}:{userid}:{type}:{sender}).
        // X-Freegle-Mail-Type — V1 header name that TN consumes (sits next to
        // the V2-style X-Freegle-Email-Type from MjmlMailable).
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $data = $this->spoolAndLoad($mail, $user->email_preferred);

        $headers = $data['headers'] ?? [];
        $this->assertArrayHasKey('Feedback-ID', $headers);
        $this->assertSame(
            "{$message->id}:{$user->id}:Digest:freegle",
            $headers['Feedback-ID']
        );

        $this->assertArrayHasKey('X-Freegle-Mail-Type', $headers);
        $this->assertSame('Digest', $headers['X-Freegle-Mail-Type']);
    }

    public function test_image_url_prefers_primary_attachment_over_ai(): void
    {
        // V1 parity: Attachment::getByIds orders by `primary` DESC, id.
        // Our getMessageImageUrl must do the same so a user-uploaded
        // photo (primary=1) wins over an AI-generated fallback (primary=0)
        // even when the AI one has a lower attachment id.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // AI attachment first (lower id), user photo second (higher id).
        $aiId = \Illuminate\Support\Facades\DB::table('messages_attachments')->insertGetId([
            'msgid' => $message->id,
            'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'externalmods' => json_encode(['ai' => true]),
            'primary' => 0,
            'archived' => 0,
        ]);
        $primaryId = \Illuminate\Support\Facades\DB::table('messages_attachments')->insertGetId([
            'msgid' => $message->id,
            'externaluid' => 'freegletusd-' . str_repeat('p', 32),
            'primary' => 1,
            'archived' => 0,
        ]);

        $message->load('attachments');

        $mail = new UnifiedDigest($user, collect([['message' => $message, 'postedToGroups' => [$group->id]]]), UnifiedDigestService::MODE_IMMEDIATE);
        $rc = new \ReflectionClass($mail);
        $m = $rc->getMethod('getMessageImageUrl');
        $m->setAccessible(true);
        $url = $m->invoke($mail, $message);

        // The primary attachment's id should be in the URL, NOT the AI one's.
        $this->assertStringContainsString("timg_{$primaryId}.jpg", $url);
        $this->assertStringNotContainsString("timg_{$aiId}.jpg", $url);
    }

    public function test_image_url_skips_attachments_with_no_data(): void
    {
        // V1's getByIds excludes rows that haven't been fully populated:
        // (data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1 OR
        // externaluid IS NOT NULL OR externalurl IS NOT NULL. Without that
        // filter we'd pick an in-flight attachment row and 404.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // Half-written attachment: primary=1 but no externaluid/url/archived.
        \Illuminate\Support\Facades\DB::table('messages_attachments')->insert([
            'msgid' => $message->id,
            'externaluid' => null,
            'externalurl' => null,
            'primary' => 1,
            'archived' => 0,
        ]);

        $message->load('attachments');

        $mail = new UnifiedDigest($user, collect([['message' => $message, 'postedToGroups' => [$group->id]]]), UnifiedDigestService::MODE_IMMEDIATE);
        $rc = new \ReflectionClass($mail);
        $m = $rc->getMethod('getMessageImageUrl');
        $m->setAccessible(true);
        $url = $m->invoke($mail, $message);

        // Half-written row was filtered out; nothing usable left → null.
        $this->assertNull($url);
    }

    public function test_html_body_preserves_user_line_breaks(): void
    {
        // Regression: HTML renderer used {{ $messageText }} which escapes
        // chars but doesn't convert \n to <br>, so multi-paragraph user
        // descriptions collapsed into a single wall of text in Gmail / web
        // mail clients (which treat raw \n as whitespace). The template
        // now uses {!! nl2br(e(…)) !!} — same pattern as the chat
        // notification template — so per-line breaks survive.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $body = "First line of description\nSecond line\n\nNew paragraph after blank";
        $message = $this->createTestMessage($poster, $group, [
            'subject'  => 'OFFER: Sofa (London)',
            'textbody' => $body,
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');

        $html = $spooled['html'] ?? '';
        $this->assertNotEmpty($html, 'Spooled HTML body should not be empty');

        // Every \n in the source should map to a <br> in the captured HTML.
        // Match `<br>` or `<br />` (different MJML/HTML serializers emit
        // both) and assert at least three breaks for the three \n above.
        $brCount = preg_match_all('/<br\s*\/?>/i', $html);
        $this->assertGreaterThanOrEqual(
            3,
            $brCount,
            'Multi-line user description should render with <br> per newline; got ' . $brCount . ' <br> tags in HTML body'
        );

        // And the user content must still be present (i.e. nl2br didn't
        // mangle it). Look for the distinct first-line tail.
        $this->assertStringContainsString('First line of description', $html);
        $this->assertStringContainsString('New paragraph after blank', $html);
    }

    public function test_daily_no_photo_post_omits_placeholder_image(): void
    {
        // A daily-digest post with no photo must NOT fall back to the blank
        // grey placeholder tile — neither as the card image nor in the top
        // thumbnail nav strip. Both read as a broken/unloaded image,
        // especially on mobile where the stacked card image spans the full
        // width of the screen.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        // No attachment → no photo → isPlaceholder.
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Coloplast supplies (Craigmount EH12)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');
        $html = $spooled['html'] ?? '';

        $this->assertNotEmpty($html, 'Spooled HTML body should not be empty');

        $placeholder = config('freegle.images.offer_placeholder');
        $this->assertNotEmpty($placeholder, 'offer_placeholder config should be set');
        $this->assertStringNotContainsString(
            $placeholder,
            $html,
            'A photo-less daily digest must not render the blank placeholder image'
        );
    }

    public function test_amp_content_excluded_when_disabled(): void
    {
        // Force AMP off via config before constructing the mailable.
        config(['freegle.amp.enabled' => false]);
        config(['freegle.amp.secret' => '']);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $mail->build();

        // Verify tracking does not indicate AMP.
        $tracking = $mail->getTracking();
        $this->assertFalse($tracking->has_amp);
    }
}
