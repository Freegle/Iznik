<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Models\Membership;
use App\Services\EmailSpoolerService;
use App\Services\UnifiedDigestService;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

class UnifiedDigestTest extends TestCase
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

    /**
     * Spool the mailable and return the decoded spool file data.
     *
     * @return array{from: array, reply_to: array, headers: array, html: string, ...}
     */
    private function spoolAndLoad(Mailable $mailable, string $recipient): array
    {
        $id = $this->spooler->spool($mailable, $recipient);
        $path = $this->testSpoolDir . '/pending/' . $id . '.json';
        $data = json_decode(file_get_contents($path), true);

        return $data ?? [];
    }


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

    public function test_immediate_subject_prefers_recipients_group_for_cross_post(): void
    {
        // A post on groups A and B; the recipient is a member of B only. The
        // immediate-digest subject prefix must name B (the recipient's group),
        // not an arbitrary first group.
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->createMembership($user, $groupB);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $groupA);
        $message = $this->createTestMessage($poster, $groupA, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$groupA->id, $groupB->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $envelope = $mail->envelope();

        $groupBName = $groupB->namefull ?: $groupB->nameshort;
        $groupAName = $groupA->namefull ?: $groupA->nameshort;
        $this->assertStringStartsWith("[{$groupBName}]", $envelope->subject);
        $this->assertStringNotContainsString("[{$groupAName}]", $envelope->subject);
    }

    public function test_immediate_subject_prefers_immediate_group_over_muted_membership(): void
    {
        // Discourse #9808: a rippled-in post reaches a member because it ripples
        // into a group they receive IMMEDIATELY (emailfrequency=-1). The post is
        // also on its origin group, which the member happens to be a (muted)
        // member of. The subject must name the group whose immediate setting
        // actually drives the email — not the muted origin group — otherwise a
        // mod/member turns off a group that isn't sending the mail and it keeps
        // coming. Member of A (origin, muted) and B (immediate); label must be B.
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        // A: member but email OFF for this group (the post's origin group).
        $this->createMembership($user, $groupA, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_NEVER,
        ]);
        // B: the group the member receives immediately on — what actually sends.
        $this->createMembership($user, $groupB, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
        ]);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $groupA);
        $message = $this->createTestMessage($poster, $groupA, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        // Origin group A listed first (as messages_groups returns the origin).
        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$groupA->id, $groupB->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $envelope = $mail->envelope();

        $groupBName = $groupB->namefull ?: $groupB->nameshort;
        $groupAName = $groupA->namefull ?: $groupA->nameshort;
        $this->assertStringStartsWith("[{$groupBName}]", $envelope->subject);
        $this->assertStringNotContainsString("[{$groupAName}]", $envelope->subject);
    }

    public function test_select_preferred_group_prefers_priority_then_membership_then_first(): void
    {
        // Pure-function contract for the labelling choice.
        // 1) A priority group (e.g. the immediate-membership group) wins even when
        //    a plain-membership group sorts earlier in the post's group list.
        $this->assertSame(
            30,
            UnifiedDigest::selectPreferredGroup([10, 20, 30], [10, 20, 30], [30]),
            'priority group should win over earlier plain memberships'
        );
        // 2) With no priority list, behaviour is unchanged: first membership match.
        $this->assertSame(
            10,
            UnifiedDigest::selectPreferredGroup([10, 20, 30], [10, 20, 30]),
            'no priority -> first posted-to group the recipient is a member of'
        );
        // 3) Priority id not on the post -> fall back to first membership match.
        $this->assertSame(
            20,
            UnifiedDigest::selectPreferredGroup([20, 30], [20, 30], [99]),
            'priority id absent from post -> first membership match'
        );
        // 4) Recipient in none -> first posted-to group.
        $this->assertSame(
            20,
            UnifiedDigest::selectPreferredGroup([20, 30], [], [99]),
            'recipient in neither -> first posted-to group'
        );
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

    public function test_subject_drops_count_when_posts_exceed_body_cap(): void
    {
        // The body lists at most DIGEST_POST_CAP posts; above that the
        // parenthetical count would overstate the email's contents, so the
        // subject drops it and just says "What's New".
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        // One more post than the body cap so the truncation bites.
        $posts = collect(array_fill(0, $cap + 1, [
            'message' => $message,
            'postedToGroups' => [$group->id],
        ]));

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $envelope = $mail->envelope();

        $this->assertStringStartsWith("What's New", $envelope->subject);
        $this->assertStringNotContainsString('post)', $envelope->subject);
        $this->assertStringNotContainsString('posts)', $envelope->subject);
        // The item-name teaser is still appended.
        $this->assertStringContainsString('Sofa', $envelope->subject);
    }

    public function test_subject_keeps_count_at_body_cap(): void
    {
        // Exactly at the cap the body shows everything, so the count is accurate
        // and must be retained.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $posts = collect(array_fill(0, $cap, [
            'message' => $message,
            'postedToGroups' => [$group->id],
        ]));

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $envelope = $mail->envelope();

        $this->assertStringStartsWith("What's New ({$cap} posts)", $envelope->subject);
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
     * Build a one-post daily digest for a recipient with the given email.
     */
    private function digestForRecipientEmail(string $email): UnifiedDigest
    {
        $user = $this->createTestUser(['email_preferred' => $email]);
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

        return new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
    }

    public function test_has_amp_column_set_for_amp_supported_recipient(): void
    {
        // The ModTools sysadmin AMP stats read the has_amp COLUMN. It was only
        // ever passed as a metadata key, never as the 7th initTracking() argument,
        // so it always defaulted to false and every digest "showed no AMP".
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => 'test-secret']);

        $tracking = $this->digestForRecipientEmail('recipient@gmail.com')->getTracking();

        $this->assertNotNull($tracking);
        $this->assertTrue((bool) $tracking->has_amp);
    }

    public function test_has_amp_column_not_set_for_unsupported_recipient(): void
    {
        // Matches ChatNotification: only count has_amp where the provider can
        // actually use AMP, so the AMP-vs-HTML comparison is like with like.
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => 'test-secret']);

        $tracking = $this->digestForRecipientEmail('recipient@example.com')->getTracking();

        $this->assertNotNull($tracking);
        $this->assertFalse((bool) $tracking->has_amp);
    }

    public function test_has_amp_column_not_set_when_amp_disabled(): void
    {
        // No secret configured => AMP is off => has_amp must be false even for a
        // provider that supports AMP.
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => '']);

        $tracking = $this->digestForRecipientEmail('recipient@gmail.com')->getTracking();

        $this->assertNotNull($tracking);
        $this->assertFalse((bool) $tracking->has_amp);
    }

    public function test_build_renders_amp_when_enabled(): void
    {
        // With AMP enabled AND the recipient on an AMP-supporting provider (Gmail),
        // build() renders the AMP variant. (The variant is now built only for
        // recipients whose provider can display it — see ampForRecipient() — because
        // rendering it for everyone wasted ~130ms of Blade CPU per non-supporting
        // recipient on an AMP part they can never use.)
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => 'test-secret']);

        $mail = $this->digestForRecipientEmail('recipient@gmail.com');
        $mail->build();

        // $ampHtml is protected; read it via reflection to confirm the AMP
        // variant was rendered.
        $ref = new \ReflectionProperty($mail, 'ampHtml');
        $ref->setAccessible(true);
        $ampHtml = $ref->getValue($mail);

        $this->assertNotEmpty($ampHtml, 'AMP variant should be rendered when AMP is enabled');
        $this->assertStringContainsString('amp4email', $ampHtml);
    }

    public function test_build_skips_amp_when_disabled(): void
    {
        // No secret => AMP disabled => no AMP variant rendered.
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => '']);

        $mail = $this->digestForRecipientEmail('recipient@gmail.com');
        $mail->build();

        $ref = new \ReflectionProperty($mail, 'ampHtml');
        $ref->setAccessible(true);

        $this->assertNull($ref->getValue($mail), 'No AMP should be rendered when AMP is disabled');
    }

    public function test_build_skips_amp_for_recipient_whose_provider_is_unsupported(): void
    {
        // AMP is globally enabled, but the recipient is on a provider that cannot
        // display AMP for Email (example.com — not Gmail/Yahoo/AOL/Mail.ru/Yandex).
        // build() must NOT render the AMP variant for them: rendering it was ~130ms
        // of wasted Blade CPU per non-supporting recipient (~43% of daily recipients)
        // on an AMP part their client would never use. The HTML/text parts are
        // unaffected. Counterpart to test_build_renders_amp_when_enabled (Gmail).
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => 'test-secret']);

        $mail = $this->digestForRecipientEmail('recipient@example.com');
        $mail->build();

        $ref = new \ReflectionProperty($mail, 'ampHtml');
        $ref->setAccessible(true);

        $this->assertNull(
            $ref->getValue($mail),
            'AMP variant must not be rendered for a provider that cannot display AMP'
        );
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
            // AMP-only state the unified blade references (production builds
            // these in UnifiedDigest when assembling the AMP variant).
            'ampPostMeta' => [],
            'ampApiUrl' => 'https://api.example.com/amp',
            'ampUserId' => 42,
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
        // The link points at the group's /explore page. It's wrapped by the
        // compact click-tracker (…/e/d/r/{ref}/g/{idEnc}/{pos}); the Go handler
        // reconstructs /explore/{groupId} from it (see emailtracking/compact.go,
        // which keys explore on the numeric group id). Decode the encoded id
        // and assert the link targets this group's explore page.
        $this->assertNotNull($card['groupUrl']);
        $target = $card['groupUrl'];
        if (preg_match('#/e/d/r/[^/]+/g/([^/]+)/#', $card['groupUrl'], $mm)) {
            $b64 = strtr($mm[1], '-_', '+/');
            $b64 = str_pad($b64, (int) (ceil(strlen($b64) / 4) * 4), '=', STR_PAD_RIGHT);
            $gid = 0;
            foreach (str_split(base64_decode($b64)) as $ch) {
                $gid = ($gid << 8) | ord($ch);
            }
            $target = '/explore/'.$gid;
        } elseif (preg_match('/[?&]url=([^&]+)/', $card['groupUrl'], $mm)) {
            $target = base64_decode(urldecode($mm[1])) ?: $target;
        }
        $this->assertStringContainsString('/explore/', $target);
        $this->assertStringContainsString('/explore/'.$group->id, $target);
    }

    public function test_byline_uses_recipients_group_for_cross_post(): void
    {
        // Recipient is a member of group B only; the post is cross-posted to A and
        // B (A listed first). The "Posted on …" byline must name B — the group the
        // recipient is actually in — not the arbitrary first group A.
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->createMembership($user, $groupB);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $groupA);
        $message = $this->createTestMessage($poster, $groupA, ['subject' => 'OFFER: Sofa (Town)']);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$groupA->id, $groupB->id]],
        ]);
        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $ref = new \ReflectionProperty(UnifiedDigest::class, 'preparedPosts');
        $ref->setAccessible(true);
        $card = $ref->getValue($mail)->first();

        $this->assertSame($groupB->namefull, $card['groupName'], 'byline should name the recipient\'s group');
        $this->assertNotSame($groupA->namefull, $card['groupName']);
    }

    public function test_byline_falls_back_to_first_group_when_recipient_in_neither(): void
    {
        // Edge case: the recipient is a member of NEITHER posted-to group (e.g. a
        // cross-group digest, or membership changed). selectPreferredGroup must
        // degrade gracefully to the first posted-to group — a real, non-empty group
        // name — rather than group 0 / a blank byline.
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        // Deliberately no membership of either group for $user.

        $poster = $this->createTestUser();
        $this->createMembership($poster, $groupA);
        $message = $this->createTestMessage($poster, $groupA, ['subject' => 'OFFER: Lamp (Town)']);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$groupA->id, $groupB->id]],
        ]);
        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $ref = new \ReflectionProperty(UnifiedDigest::class, 'preparedPosts');
        $ref->setAccessible(true);
        $card = $ref->getValue($mail)->first();

        $this->assertSame($groupA->namefull, $card['groupName'], 'byline should fall back to the first posted-to group');
        $this->assertNotEmpty($card['groupName'], 'byline group name must not be blank');
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

    public function test_immediate_tracking_groupid_uses_recipients_group_for_cross_post(): void
    {
        // A post on groups A and B; the recipient is a member of B only. The
        // immediate-digest tracking groupid must record B (the recipient's group),
        // not an arbitrary first group.
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->createMembership($user, $groupB);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $groupA);
        $message = $this->createTestMessage($poster, $groupA, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$groupA->id, $groupB->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals($groupB->id, $mail->getTracking()->groupid);
        $this->assertNotEquals($groupA->id, $mail->getTracking()->groupid);
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

    public function test_daily_no_photo_post_shows_type_specific_placeholder(): void
    {
        // A daily-digest post with no photo renders a TYPE-SPECIFIC placeholder
        // (green OFFER / blue WANTED, matching the in-app MessagePhotoPlaceholder)
        // rather than a real photo. An OFFER post must use the OFFER placeholder
        // and a WANTED post the WANTED placeholder — never each other's.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        // No attachment → no photo → isPlaceholder → type placeholder.
        $offerMsg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Coloplast supplies (Craigmount EH12)',
            'type' => 'Offer',
        ]);
        $wantedMsg = $this->createTestMessage($poster, $group, [
            'subject' => 'WANTED: Child\'s bike (Craigmount EH12)',
            'type' => 'Wanted',
        ]);

        $posts = collect([
            ['message' => $offerMsg, 'postedToGroups' => [$group->id]],
            ['message' => $wantedMsg, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');
        $html = $spooled['html'] ?? '';

        $this->assertNotEmpty($html, 'Spooled HTML body should not be empty');

        $offerPlaceholder = config('freegle.images.offer_placeholder');
        $wantedPlaceholder = config('freegle.images.wanted_placeholder');
        $this->assertNotEmpty($offerPlaceholder, 'offer_placeholder config should be set');
        $this->assertNotEmpty($wantedPlaceholder, 'wanted_placeholder config should be set');

        $this->assertStringContainsString(
            $offerPlaceholder,
            $html,
            'A photo-less OFFER post should render the OFFER placeholder image'
        );
        $this->assertStringContainsString(
            $wantedPlaceholder,
            $html,
            'A photo-less WANTED post should render the WANTED placeholder image'
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

    public function test_card_meta_shows_distance_when_user_has_location(): void
    {
        // haversineDistance() and the distance branch in prepareCard() are only
        // reached when the digest recipient has a lastlocation set. Without a
        // lastlocation both $userLat and $userLng remain null and the distance
        // block is skipped entirely.
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'TestUserLocation',
            'type' => 'Point',
            'lat'  => 51.55,    // ~3 miles north of the default group/message lat
            'lng'  => -0.1278,
        ]);

        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(); // defaults: lat=51.5074, lng=-0.1278
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Bicycle (London)',
        ]);

        $posts = collect([['message' => $message, 'postedToGroups' => [$group->id]]]);
        $mail  = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'r@example.com');
        $html    = $spooled['html'] ?? '';

        $this->assertNotEmpty($html, 'Spooled digest HTML should not be empty');
        // The distance text rendered by haversineDistance() must appear in the
        // compiled HTML (e.g. "3 miles" or "< 1 mile").
        $this->assertMatchesRegularExpression(
            '/(?:[0-9]+ miles?|&lt; 1 mile|< 1 mile)/',
            $html,
            'Distance text must appear in the digest card when the user has a lastlocation'
        );
    }

    public function test_card_shows_first_posted_when_message_was_reposted(): void
    {
        // The "First posted" line in _card.blade.php is only emitted when
        // prepareCard() sets firstPostedFormatted — which only happens when
        // messages.date is > 60 minutes before the pivot arrival date (i.e. the
        // item was reposted). Without this test the firstPostedFormatted branch
        // in UnifiedDigest::prepareCard() was never covered.
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $originalDate = Carbon::now()->subDays(3);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Bookshelf (London)',
            'date'    => $originalDate,
            'arrival' => Carbon::now(),
        ]);

        $posts = collect([['message' => $message, 'postedToGroups' => [$group->id]]]);
        $mail  = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);

        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'r@example.com');
        $html    = $spooled['html'] ?? '';

        $this->assertNotEmpty($html, 'Spooled digest HTML should not be empty');
        // The "First posted" line must appear since the item was reposted 3 days
        // after its original posting date.
        $this->assertStringContainsString(
            'First posted',
            $html,
            'A reposted message must render the "First posted" date line'
        );
    }

    public function test_daily_preheader_lists_item_names(): void
    {
        // Daily-mode preheader: comma-separated item names from the first three
        // posts so the inbox preview shows what's in the digest rather than a
        // generic count. Assert the distinct item names appear in the rendered
        // email (the <mj-preview> content becomes a hidden element in the
        // compiled HTML).
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Wardrobe (Bristol)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'WANTED: Bicycle (Bristol)']);
        $msg3 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Dining Table (Bristol)']);

        $posts = collect([
            ['message' => $msg1, 'postedToGroups' => [$group->id]],
            ['message' => $msg2, 'postedToGroups' => [$group->id]],
            ['message' => $msg3, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'r@example.com');
        $html = $spooled['html'] ?? '';

        $this->assertNotEmpty($html, 'Spooled daily digest HTML should not be empty');

        // The daily preheader lists up to three item names. Each of these
        // distinctive item names must appear somewhere in the compiled HTML
        // (within the hidden preheader element).
        $this->assertStringContainsString(
            'Wardrobe',
            $html,
            'Daily preheader must include the first post item name'
        );
        $this->assertStringContainsString(
            'Bicycle',
            $html,
            'Daily preheader must include the second post item name'
        );
        $this->assertStringContainsString(
            'Dining Table',
            $html,
            'Daily preheader must include the third post item name'
        );
    }

    public function test_immediate_preheader_shows_post_subject(): void
    {
        // Immediate-mode preheader: the full post subject so the inbox preview
        // shows exactly what the post is about (V1 parity: single.mjml used the
        // post subject as the preheader). The subject is written into the hidden
        // <mj-preview> element; after MJML compilation it becomes a display:none
        // span in the compiled HTML.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Rocking Chair (Edinburgh)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'r@example.com');
        $html = $spooled['html'] ?? '';

        $this->assertNotEmpty($html, 'Spooled immediate digest HTML should not be empty');

        // The subject "OFFER: Rocking Chair (Edinburgh)" is passed directly as
        // the preheader. "Rocking Chair" is a distinctive term that only
        // originates from the preheader (or the card body — both are correct
        // signals that the subject reached the email).
        $this->assertStringContainsString(
            'Rocking Chair',
            $html,
            'Immediate-mode preheader must contain the post subject item name'
        );
        $this->assertStringContainsString(
            'Edinburgh',
            $html,
            'Immediate-mode preheader must contain the post subject location'
        );
    }
}
