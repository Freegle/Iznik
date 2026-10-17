<?php

namespace Tests\Unit\Services;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\AutoApproveService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoApproveServiceHoldTest extends TestCase
{
    protected AutoApproveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoApproveService();
    }

    private function makeApprovable48h(): array
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subDays(5)]);
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival'    => now()->subHours(50),
            ]);

        return [$user, $group, $message];
    }

    public function test_48h_hold_until_future_keeps_post_pending(): void
    {
        [$user, $group, $message] = $this->makeApprovable48h();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['autoapprove_hold_until' => now()->addMinutes(10)]);

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_48h_hold_until_past_allows_approval(): void
    {
        [$user, $group, $message] = $this->makeApprovable48h();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['autoapprove_hold_until' => now()->subMinutes(1)]);

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);
    }

    public function test_48h_fallback_still_catches_a_quality_sample(): void
    {
        // A quality sample is held for a moderator's verdict; the 48h sweep stays its
        // backstop, so a sample nobody looked at does not wait in Pending for ever.
        [$user, $group, $message] = $this->makeApprovable48h();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['quality_sample' => 1]);

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);
        $this->assertDatabaseHas('logs', [
            'msgid'   => $message->id,
            'type'    => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_48h_hold_rechecked_at_write_time(): void
    {
        // The candidate query and the UPDATE both check the hold, so a hold bumped
        // between them (mod opens the Pending page mid-run) wins. Simulate the write-time
        // side directly: candidate eligible, but the row's hold is bumped before
        // approveOnGroup's UPDATE executes — the UPDATE must not fire, and crucially no
        // Autoapproved log may be written for an approval that did not happen.
        [$user, $group, $message] = $this->makeApprovable48h();

        $candidate = (object) [
            'msgid'    => $message->id,
            'groupid'  => $group->id,
            'fromuser' => $user->id,
            'spamtype' => null,
            'subject'  => $message->subject,
        ];

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['autoapprove_hold_until' => now()->addMinutes(10)]);

        $method = new \ReflectionMethod($this->service, 'approveOnGroup');
        $method->setAccessible(true);
        $method->invoke($this->service, $candidate, $group->id);

        $this->assertDatabaseHas('messages_groups', [
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', [
            'msgid'   => $message->id,
            'type'    => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    private function seedDecision(int $msgid, int $groupid, string $verdict, string $end): void
    {
        MessageAutomod::create([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '5', 'verdict' => $verdict, 'end_node' => $end, 'reason' => null,
            'path' => [], 'created' => now()->subHour(),
        ]);
    }

    /**
     * On an approve-mode community a post automated review held (money, medicine, a loan)
     * has a recorded reason to wait for a moderator. The 48h sweep is for posts nobody has
     * looked at, not a way round that reason.
     */
    public function test_48h_fallback_skips_a_post_automated_review_held_on_an_approve_mode_community(): void
    {
        config(['freegle.autoapprove.enabled' => true, 'freegle.automod.shadow_group_ids' => '']);
        [$user, $group, $message] = $this->makeApprovable48h();
        $this->seedDecision($message->id, $group->id, MessageAutomod::VERDICT_HOLD, 'HOLD_MONEY');

        $stats = $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id, 'groupid' => $group->id, 'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', ['msgid' => $message->id, 'type' => 'Message', 'subtype' => 'Autoapproved']);
        $this->assertGreaterThanOrEqual(1, $stats['skipped']);
    }

    public function test_48h_fallback_still_runs_when_the_review_could_not_decide(): void
    {
        config(['freegle.autoapprove.enabled' => true, 'freegle.automod.shadow_group_ids' => '']);
        [$user, $group, $message] = $this->makeApprovable48h();
        $this->seedDecision($message->id, $group->id, MessageAutomod::VERDICT_HOLD, 'UNAVAILABLE');

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id, 'groupid' => $group->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);
    }

    public function test_48h_fallback_ignores_a_hold_recorded_outside_approve_mode(): void
    {
        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => '']);
        [$user, $group, $message] = $this->makeApprovable48h();
        $this->seedDecision($message->id, $group->id, MessageAutomod::VERDICT_HOLD, 'HOLD_MONEY');

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id, 'groupid' => $group->id, 'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);
    }
}
