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
}
