<?php

namespace Tests\Unit\Services;

use App\Mail\Digest\UnifiedDigest;
use App\Models\Group;
use App\Models\GroupDigest;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UnifiedDigestServiceGroupModeTest extends TestCase
{
    protected UnifiedDigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnifiedDigestService();
        Mail::fake();
    }

    public function test_getMembersForGroup_returns_members_at_frequency(): void
    {
        $group = $this->createTestGroup();
        $hourlyUser = $this->createTestUser();
        $dailyUser  = $this->createTestUser();
        $neverUser  = $this->createTestUser();

        $this->createMembership($hourlyUser, $group, ['emailfrequency' => 1]);
        $this->createMembership($dailyUser,  $group, ['emailfrequency' => 24]);
        $this->createMembership($neverUser,  $group, ['emailfrequency' => 0]);

        $members = $this->callProtected($this->service, 'getMembersForGroup', [$group, 1]);

        $this->assertCount(1, $members);
        $this->assertEquals($hourlyUser->id, $members->first()->userid);
    }

    public function test_getPostsForGroup_returns_messages_for_group_only(): void
    {
        $poster = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $msg1 = $this->createTestMessage($poster, $group1);
        $msg2 = $this->createTestMessage($poster, $group2);

        $tracker = GroupDigest::firstOrCreate(
            ['groupid' => $group1->id, 'frequency' => 1],
            ['msgid' => null, 'msgdate' => null]
        );

        $posts = $this->callProtected($this->service, 'getPostsForGroup', [$group1, $tracker]);

        $this->assertCount(1, $posts);
        $this->assertEquals($msg1->id, $posts->first()->id);
    }

    public function test_getPostsForGroup_respects_tracker_cutoff(): void
    {
        $poster = $this->createTestUser();
        $group  = $this->createTestGroup();

        $oldMsg = $this->createTestMessage($poster, $group, [
            'arrival' => now()->subHours(3),
            'date'    => now()->subHours(3),
        ]);
        $newMsg = $this->createTestMessage($poster, $group);

        // Tracker records the old message as the last one sent.
        $tracker = GroupDigest::firstOrCreate(
            ['groupid' => $group->id, 'frequency' => 1],
            ['msgid' => $oldMsg->id, 'msgdate' => now()->subHours(2)]
        );

        $posts = $this->callProtected($this->service, 'getPostsForGroup', [$group, $tracker]);

        $this->assertCount(1, $posts);
        $this->assertEquals($newMsg->id, $posts->first()->id);
    }

    public function test_sendGroupDigests_sends_email_to_member(): void
    {
        $poster    = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group     = $this->createTestGroup();

        $this->createTestMessage($poster, $group);
        $this->createMembership($recipient, $group, ['emailfrequency' => 1]);

        $stats = $this->service->sendGroupDigests($group, 1);

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertSent(UnifiedDigest::class, function ($mail) use ($recipient) {
            return $mail->user->id === $recipient->id;
        });
    }

    public function test_sendGroupDigests_does_not_email_poster_their_own_post(): void
    {
        $poster = $this->createTestUser();
        $group  = $this->createTestGroup();

        $this->createTestMessage($poster, $group);
        $this->createMembership($poster, $group, ['emailfrequency' => 1]);

        $stats = $this->service->sendGroupDigests($group, 1);

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_sendGroupDigests_sends_mode_group_email(): void
    {
        $poster    = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group     = $this->createTestGroup();

        $this->createTestMessage($poster, $group);
        $this->createMembership($recipient, $group, ['emailfrequency' => 1]);

        $this->service->sendGroupDigests($group, 1);

        Mail::assertSent(UnifiedDigest::class, function ($mail) {
            return $mail->mode === UnifiedDigestService::MODE_GROUP;
        });
    }

    public function test_sendGroupDigests_updates_group_digest_tracker(): void
    {
        $poster    = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group     = $this->createTestGroup();

        $this->createTestMessage($poster, $group);
        $this->createMembership($recipient, $group, ['emailfrequency' => 1]);

        $this->service->sendGroupDigests($group, 1);

        $tracker = GroupDigest::where('groupid', $group->id)->where('frequency', 1)->first();
        $this->assertNotNull($tracker);
        $this->assertNotNull($tracker->msgid);
        $this->assertNotNull($tracker->ended);
    }

    public function test_sendGroupDigests_skips_closed_group(): void
    {
        $poster    = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group     = $this->createTestGroup(['settings' => ['closed' => true]]);

        $this->createTestMessage($poster, $group);
        $this->createMembership($recipient, $group, ['emailfrequency' => 1]);

        $stats = $this->service->sendGroupDigests($group, 1);

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_sendGroupDigests_dry_run_does_not_send(): void
    {
        $poster    = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group     = $this->createTestGroup();

        $this->createTestMessage($poster, $group);
        $this->createMembership($recipient, $group, ['emailfrequency' => 1]);

        $stats = $this->service->sendGroupDigests($group, 1, dryRun: true);

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_sendGroupDigests_sends_correct_posts_to_each_member(): void
    {
        $poster     = $this->createTestUser();
        $recipient1 = $this->createTestUser();
        $recipient2 = $this->createTestUser();
        $group      = $this->createTestGroup();

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);
        $this->createMembership($recipient1, $group, ['emailfrequency' => 24]);
        $this->createMembership($recipient2, $group, ['emailfrequency' => 24]);

        $stats = $this->service->sendGroupDigests($group, 24);

        $this->assertEquals(2, $stats['emails_sent']);
        $this->assertEquals(2, $stats['members_processed']);
        Mail::assertSentCount(2);
    }

    // -------------------------------------------------------------------------

    /**
     * Call a protected method for white-box testing.
     */
    protected function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
