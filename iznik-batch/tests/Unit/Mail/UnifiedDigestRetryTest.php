<?php

namespace Tests\Unit\Mail;

use App\Mail\Contracts\RetryableMailable;
use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verifies UnifiedDigest's RetryableMailable contract: the descriptor captures
 * the IDs needed to rebuild, and rebuildFromDescriptor reconstructs an
 * equivalent digest from fresh DB state (or cancels when data has gone).
 */
class UnifiedDigestRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_is_a_retryable_mailable(): void
    {
        $this->assertInstanceOf(RetryableMailable::class, $this->makeDigest());
    }

    public function test_descriptor_captures_user_mode_and_posts(): void
    {
        [$digest, $user, $group, $message] = $this->makeDigestWithContext();

        $descriptor = $digest->mailDescriptor();

        $this->assertSame($user->id, $descriptor['userid']);
        $this->assertSame(UnifiedDigestService::MODE_IMMEDIATE, $descriptor['mode']);
        $this->assertSame(
            [['msgid' => $message->id, 'groups' => [$group->id]]],
            $descriptor['posts']
        );
        // Immediate digests have no "came and went" section.
        $this->assertSame([], $descriptor['completed']);
    }

    public function test_descriptor_round_trips_daily_completed_posts(): void
    {
        // The daily digest's "came and went" (Taken/Received) section is handed
        // to the constructor as completedPosts. A durable retry must round-trip
        // those message ids, otherwise the rebuilt daily digest renders without
        // the whole section — the gap this guards against.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $livePost = $this->createTestMessage($poster, $group);
        $completedMessage = $this->createTestMessage($poster, $group);

        $digest = new UnifiedDigest(
            $recipient,
            collect([['message' => $livePost, 'postedToGroups' => [$group->id]]]),
            UnifiedDigestService::MODE_DAILY,
            collect(),
            collect([$completedMessage])
        );

        // Descriptor captures the completed message id.
        $this->assertSame([$completedMessage->id], $digest->mailDescriptor()['completed']);

        // Rebuild re-fetches it and restores the completedPosts collection.
        $rebuilt = UnifiedDigest::rebuildFromDescriptor($digest->mailDescriptor());
        $this->assertInstanceOf(UnifiedDigest::class, $rebuilt);

        $ref = new \ReflectionProperty(UnifiedDigest::class, 'completedPosts');
        $ref->setAccessible(true);
        $rebuiltCompleted = $ref->getValue($rebuilt);

        $this->assertCount(1, $rebuiltCompleted);
        $this->assertSame($completedMessage->id, $rebuiltCompleted->first()->id);
        // And it round-trips through a second descriptor.
        $this->assertSame([$completedMessage->id], $rebuilt->mailDescriptor()['completed']);
    }

    public function test_rebuild_drops_completed_posts_that_have_since_been_deleted(): void
    {
        // A completed message deleted between send and retry must drop out of
        // the rebuilt section rather than blow up the rebuild.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $livePost = $this->createTestMessage($this->createTestUser(), $group);

        $rebuilt = UnifiedDigest::rebuildFromDescriptor([
            'userid' => $user->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'posts' => [['msgid' => $livePost->id, 'groups' => [$group->id]]],
            'completed' => [999999999],
        ]);

        $this->assertInstanceOf(UnifiedDigest::class, $rebuilt);

        $ref = new \ReflectionProperty(UnifiedDigest::class, 'completedPosts');
        $ref->setAccessible(true);
        $this->assertCount(0, $ref->getValue($rebuilt));
    }

    public function test_rebuild_from_descriptor_reconstructs_equivalent_digest(): void
    {
        [$digest, $user, $group, $message] = $this->makeDigestWithContext();

        $rebuilt = UnifiedDigest::rebuildFromDescriptor($digest->mailDescriptor());

        $this->assertInstanceOf(UnifiedDigest::class, $rebuilt);
        // Round-trips back to the same identifiers.
        $this->assertSame($user->id, $rebuilt->user->id);
        $this->assertSame($user->id, $rebuilt->mailDescriptor()['userid']);
        $this->assertSame(
            [['msgid' => $message->id, 'groups' => [$group->id]]],
            $rebuilt->mailDescriptor()['posts']
        );
    }

    public function test_rebuild_reloads_group_sponsors_for_immediate(): void
    {
        // A durable retry used to construct with an empty sponsor Collection,
        // so retried immediate digests shipped without sponsor credit. The
        // rebuild must re-derive the post's group sponsors (V1 single-group
        // scope).
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);
        $message = $this->createTestMessage($poster, $group);

        \Illuminate\Support\Facades\DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Retry Sponsor',
            'linkurl' => 'https://retry.example.com',
            'imageurl' => 'https://retry.example.com/logo.png',
            'tagline' => 'Still here on retry',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'R',
            'contactemail' => 'r@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        $rebuilt = UnifiedDigest::rebuildFromDescriptor([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_IMMEDIATE,
            'posts' => [['msgid' => $message->id, 'groups' => [$group->id]]],
        ]);

        $this->assertInstanceOf(UnifiedDigest::class, $rebuilt);

        // sponsors is protected — read it via reflection.
        $ref = new \ReflectionProperty(UnifiedDigest::class, 'sponsors');
        $ref->setAccessible(true);
        $sponsors = $ref->getValue($rebuilt);

        $this->assertCount(1, $sponsors);
        $this->assertEquals('Retry Sponsor', $sponsors->first()->name);
    }

    public function test_rebuild_uses_recipients_group_for_sponsors_on_cross_post(): void
    {
        // A cross-post to groups A and B; recipient is only a member of B.
        // rebuildFromDescriptor must pick B for the sponsors lookup (not A).
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->createMembership($recipient, $groupB);
        $message = $this->createTestMessage($poster, $groupA);

        \Illuminate\Support\Facades\DB::table('groups_sponsorship')->insert([
            'groupid' => $groupB->id,
            'name' => 'GroupB Sponsor',
            'linkurl' => 'https://groupb.example.com',
            'imageurl' => 'https://groupb.example.com/logo.png',
            'tagline' => 'GroupB sponsor',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'B',
            'contactemail' => 'b@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        $rebuilt = UnifiedDigest::rebuildFromDescriptor([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_IMMEDIATE,
            'posts' => [['msgid' => $message->id, 'groups' => [$groupA->id, $groupB->id]]],
        ]);

        $this->assertInstanceOf(UnifiedDigest::class, $rebuilt);

        $ref = new \ReflectionProperty(UnifiedDigest::class, 'sponsors');
        $ref->setAccessible(true);
        $sponsors = $ref->getValue($rebuilt);

        $this->assertCount(1, $sponsors);
        $this->assertEquals('GroupB Sponsor', $sponsors->first()->name);
    }

    public function test_rebuild_returns_null_for_unknown_user(): void
    {
        $rebuilt = UnifiedDigest::rebuildFromDescriptor([
            'userid' => 999999999,
            'mode' => UnifiedDigestService::MODE_IMMEDIATE,
            'posts' => [['msgid' => 1, 'groups' => [1]]],
        ]);

        $this->assertNull($rebuilt);
    }

    public function test_rebuild_returns_null_when_all_posts_missing(): void
    {
        $user = $this->createTestUser();

        $rebuilt = UnifiedDigest::rebuildFromDescriptor([
            'userid' => $user->id,
            'mode' => UnifiedDigestService::MODE_IMMEDIATE,
            'posts' => [['msgid' => 999999999, 'groups' => [1]]],
        ]);

        $this->assertNull($rebuilt);
    }

    private function makeDigest(): UnifiedDigest
    {
        return $this->makeDigestWithContext()[0];
    }

    /**
     * @return array{0: UnifiedDigest, 1: \App\Models\User, 2: \App\Models\Group, 3: \App\Models\Message}
     */
    private function makeDigestWithContext(): array
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);
        $message = $this->createTestMessage($poster, $group);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $digest = new UnifiedDigest($recipient, $posts, UnifiedDigestService::MODE_IMMEDIATE, collect());

        return [$digest, $recipient, $group, $message];
    }
}
