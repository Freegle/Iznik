<?php

namespace Tests\Unit\Services;

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

    public function test_48h_fallback_leaves_quality_sample_for_a_human(): void
    {
        // A post AutoApproveCleanService held back as a quality-check sample is waiting
        // for a moderator's verdict. The 48h fallback must not sweep it up — that would
        // silently drain the sample and break the sample-vs-population error comparison.
        [$user, $group, $message] = $this->makeApprovable48h();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['quality_sample' => 1]);

        $this->service->process();

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
}
