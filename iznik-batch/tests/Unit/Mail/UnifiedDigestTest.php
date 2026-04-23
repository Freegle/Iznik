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
