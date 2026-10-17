<?php

namespace Tests\Unit\Services;

use App\Models\MessageGroup;
use App\Services\AutoApproveCleanService;
use App\Services\Lockdown\LockdownHoldsService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LokiSpy;
use Tests\Support\MakesApprovablePosts;
use Tests\TestCase;

/**
 * AutoApproveCleanService::process under the lockdown switch (plan 2026-09-27-lockdown-switch.md,
 * section 11.4: nothing promotes while posts are held). The clean cron is a third way a post
 * goes live by itself, beside ContentCheckService and AutoApproveService, and until now it was
 * the one that ignored the press: a flagged wave of new accounts stayed Pending (the content
 * check held it), the chart approved it, and twenty minutes later the cron published it.
 * Ordinary behaviour is covered by AutoApproveCleanServiceTest.
 */
class AutoApproveCleanServiceLockdownTest extends TestCase
{
    use MakesApprovablePosts;

    private LockdownService $lockdown;

    private LokiSpy $loki;

    private AutoApproveCleanService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('lockdowns')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached by an
        // earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();

        $this->lockdown = new LockdownService();
        $this->loki = new LokiSpy();
        $this->service = new AutoApproveCleanService($this->loki, $this->lockdown);

        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
            'freegle.autoapprove.delay_minutes' => 20,
            'freegle.autoapprove.quality_check_percent' => 0,
            'freegle.autoapprove.danger_log_days' => 90,
            'freegle.automod.shadow_group_ids' => '',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('lockdowns')->delete();
        LockdownService::flushCache();
        parent::tearDown();
    }

    public function test_does_not_publish_while_posts_are_held(): void
    {
        $this->lockdown->press(null, 'test');
        [$user, $group, $message] = $this->makeApprovable();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['approved']);
        $this->assertStillPending($message->id, $group->id);
        $this->assertNotEmpty(
            array_filter($this->loki->eventsOf('autoapprove', 'skipped'), fn ($e) => $e['context']['reason'] === 'lockdown'),
            'the skip is named in Loki so "why is nothing publishing" has an answer'
        );
    }

    public function test_publishes_again_once_posts_are_lifted(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);
        [$user, $group, $message] = $this->makeApprovable();

        $stats = $this->service->process();

        $this->assertSame(1, $stats['approved']);
        $this->assertApproved($message->id, $group->id);
    }

    /**
     * Section 11.6 point 2: a press landing partway through one process() call must stop the
     * NEXT post it looks at, not wait for the next invocation. The top-of-run guard is the
     * first held() call, so the first per-post check inside the loop is the second.
     */
    public function test_stops_publishing_further_posts_once_pressed_mid_run(): void
    {
        [$userA, $groupA, $messageA] = $this->makeApprovable();
        [$userB, $groupB, $messageB] = $this->makeApprovable();

        $lockdown = new class extends LockdownService {
            public int $calls = 0;

            public function held(string $surface): bool
            {
                $this->calls++;
                $result = parent::held($surface);
                if ($this->calls === 2) {
                    $this->press(null, 'pressed mid run');
                }

                return $result;
            }
        };
        $service = new AutoApproveCleanService($this->loki, $lockdown);

        $stats = $service->process();

        $this->assertSame(1, $stats['approved'], 'the post already under way when the press landed completes');
        $this->assertSame(
            1,
            DB::table('messages_groups')
                ->whereIn('msgid', [$messageA->id, $messageB->id])
                ->where('collection', MessageGroup::COLLECTION_PENDING)
                ->count(),
            'the other post is left for the next run, not published'
        );
        $skipped = array_filter($this->loki->eventsOf('autoapprove', 'skipped'), fn ($e) => $e['context']['reason'] === 'lockdown');
        $this->assertCount(1, $skipped);
        $this->assertArrayHasKey('msgid', array_values($skipped)[0]['context'], 'a mid-run skip names the post');
    }

    /**
     * A hold not yet resolved (outcome NULL) is ContentCheckService's to admit through the
     * ordinary decision (admitHeldPost) once posts is lifted; this cron must not publish it
     * directly and skip that check, even when the press itself has been lifted.
     */
    public function test_never_publishes_a_post_with_an_open_lockdown_hold(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);
        [$user, $group, $message] = $this->makeApprovable();
        $this->hold($message->id, $user->id, null);

        $stats = $this->service->process();

        $this->assertSame(0, $stats['approved']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_a_resolved_lockdown_hold_does_not_stop_the_post(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);
        [$user, $group, $message] = $this->makeApprovable();
        $this->hold($message->id, $user->id, 'kept_pending');

        $stats = $this->service->process();

        $this->assertSame(1, $stats['approved']);
        $this->assertApproved($message->id, $group->id);
    }

    public function test_acks_the_press_so_the_presser_sees_this_loop_take_effect(): void
    {
        $this->lockdown->press(null, 'test');
        $this->makeApprovable();

        $this->service->process();

        $ack = DB::table('lockdown_acks')->where('loop', 'auto-approve-clean')->first();
        $this->assertNotNull($ack, 'auto-approve-clean must ack');
        $this->assertSame((int) $this->lockdown->current()->id, (int) $ack->lockdownrowid);
        $this->assertContains('auto-approve-clean', LockdownService::ACK_LOOPS, 'lockdown:report lists a loop that never ticked');
    }

    private function hold(int $msgid, int $userid, ?string $outcome): void
    {
        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_POST,
            'refid' => $msgid,
            'userid' => $userid,
            'created' => now(),
            'outcome' => $outcome,
        ]);
    }
}
