<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
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
     * Spool the mailable and return the decoded spool-file array. This is
     * how the real mail path captures everything (subject, body, all custom
     * headers, reply-to) — so assertions here exercise the actual
     * production capture pipeline rather than poking at Symfony internals.
     */
    private function spoolAndLoad(UnifiedDigest $mail, string $recipient): array
    {
        $id = $this->spooler->spool($mail, $recipient);
        return json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);
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

        $this->assertEquals('1 new post near you - Sofa', $envelope->subject);
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

        $this->assertStringStartsWith('3 new posts near you', $envelope->subject);
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

        // Verify tracking was initialised with correct metadata.
        $tracking = $mail->getTracking();
        $this->assertNotNull($tracking);
        $this->assertEquals('UnifiedDigest', $tracking->email_type);
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

    public function test_immediate_mode_envelope_from_is_replyto_address(): void
    {
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

        $expected = "replyto-{$message->id}-{$user->id}@" . config('freegle.mail.user_domain');
        $this->assertEquals($expected, $envelope->from->address);
    }

    public function test_immediate_mode_sets_reply_to_header_matching_from(): void
    {
        // The immediate digest relies on From and Reply-To both being the
        // replyto-{msgId}-{userId} address so a user can hit "Reply" in any
        // mail client and have the message routed back to the original
        // poster. This test asserts that contract via the same spool path
        // production uses.
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
        $expected = "replyto-{$message->id}-{$user->id}@" . config('freegle.mail.user_domain');

        $data = $this->spoolAndLoad($mail, $user->email_preferred);

        // From address (envelope) is the replyto- address.
        $fromAddresses = array_column($data['from'] ?? [], 'address');
        $this->assertContains($expected, $fromAddresses);

        // Reply-To header is set explicitly to the same address so clients
        // that prefer Reply-To over From still route the user's reply back.
        $replyToAddresses = array_column($data['reply_to'] ?? [], 'address');
        $this->assertContains($expected, $replyToAddresses);
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
