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

    /**
     * A join is about one community and needs nothing recorded against the member. A move, a
     * return or a switch to immediate mail does, so a join that follows one before the drain
     * must not take its place.
     */
    public function test_a_join_does_not_replace_an_earlier_reason(): void
    {
        $user = $this->createTestUser();

        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_RETURNED);
        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_JOINED);

        $this->assertSame(
            'returned',
            DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('reason')
        );
    }

    /**
     * A change found late is queued with the time it happened. It must not move a waiting
     * row's time backwards.
     */
    public function test_an_older_time_does_not_move_a_waiting_row_back(): void
    {
        $user = $this->createTestUser();
        $now = now()->startOfSecond();

        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_FREQUENCY, $now);
        ReachMemberQueueService::enqueue($user->id, ReachMemberQueueService::REASON_MOVED, $now->copy()->subHours(6));

        $this->assertSame(
            $now->toDateTimeString(),
            DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('added')
        );
    }
}
