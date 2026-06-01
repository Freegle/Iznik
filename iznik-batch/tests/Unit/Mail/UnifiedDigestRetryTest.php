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
