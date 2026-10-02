<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReachMemberQueueService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The member side of reach mail. A member who becomes eligible after a post's reach settled
 * (joins, moves, returns after a long absence, switches to immediate mail) is queued here by
 * the codepath that changed them, and the reach mail job drains the queue.
 */
class ReachMemberQueueServiceTest extends TestCase
{
    public function test_enqueue_writes_one_row_for_the_member(): void
    {
        $user = $this->createTestUser();

        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_JOINED);

        $this->assertDatabaseHas('rippling_reach_member_pending', [
            'userid' => $user->id,
            'reason' => 'joined',
        ]);
    }

    public function test_repeated_signals_for_one_member_collapse_to_one_row(): void
    {
        $user = $this->createTestUser();

        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_JOINED);
        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_MOVED);
        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_JOINED);

        $this->assertSame(
            1,
            DB::table('rippling_reach_member_pending')->where('userid', $user->id)->count(),
            'a member is queued once however many signals arrive before the drain'
        );
    }

    public function test_the_latest_reason_wins_when_signals_collapse(): void
    {
        $user = $this->createTestUser();

        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_JOINED);
        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_RETURNED);

        $this->assertSame(
            'returned',
            DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('reason')
        );
    }
}
