<?php

namespace Tests\Unit\Services\Automod;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\Automod\AutomodService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\LokiSpy;
use Tests\TestCase;

/**
 * messages:automod under the lockdown switch. While posts are held nothing publishes, so a
 * review is pure cost, and a flood is exactly when it would be most expensive: the run makes
 * no model call, records nothing and leaves every row for the first run after the lift
 * (the content check re-stamps what the lockdown held, so those decisions are redone anyway).
 */
class AutomodServiceLockdownTest extends TestCase
{
    private LockdownService $lockdown;

    private LokiSpy $loki;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('lockdowns')->delete();
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->loki = new LokiSpy();

        config([
            'freegle.automod.url' => 'http://automod-test:8080',
            'freegle.automod.timeout' => 20,
            'freegle.automod.shadow_group_ids' => '',
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('lockdowns')->delete();
        LockdownService::flushCache();
        parent::tearDown();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Group, 2: \App\Models\Message} */
    private function makeCandidate(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subMinutes(21),
                'contentcheck_checked_at' => now()->subMinutes(20),
                'contentcheck_reasons' => null,
                'deleted' => 0,
                'rippled_in' => 0,
            ]);

        return [$user, $group, $message];
    }

    private function fakeApprove(): void
    {
        Http::fake(['*/review' => Http::response(json_encode([
            'version' => 'v1', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => [],
        ]), 200)]);
    }

    public function test_makes_no_model_call_while_posts_are_held(): void
    {
        $this->fakeApprove();
        $this->lockdown->press(null, 'test');
        [$user, $group, $message] = $this->makeCandidate();

        $stats = (new AutomodService(null, $this->loki, $this->lockdown))->process();

        Http::assertNothingSent();
        $this->assertSame(0, $stats['reviewed']);
        $this->assertSame(1, $stats['lockdown']);
        $this->assertNull(DB::table('messages_automod')->where('msgid', $message->id)->first(), 'nothing recorded: the row waits for the first run after the lift');
        $this->assertNotEmpty($this->loki->jobs, 'the run still says it did nothing, and why');
        $this->assertTrue($this->loki->jobs[0]['context']['lockdown']);
    }

    public function test_reviews_again_once_posts_are_lifted(): void
    {
        $this->fakeApprove();
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);
        [$user, $group, $message] = $this->makeCandidate();

        $stats = (new AutomodService(null, $this->loki, $this->lockdown))->process();

        $this->assertSame(1, $stats['reviewed']);
        $this->assertNotNull(DB::table('messages_automod')->where('msgid', $message->id)->first());
    }

    public function test_acks_the_press(): void
    {
        $this->lockdown->press(null, 'test');

        (new AutomodService(null, $this->loki, $this->lockdown))->process();

        $this->assertNotNull(DB::table('lockdown_acks')->where('loop', 'automod')->first());
        $this->assertContains('automod', LockdownService::ACK_LOOPS);
    }

    public function test_stops_taking_candidates_once_pressed_mid_run(): void
    {
        $this->fakeApprove();
        $this->makeCandidate();
        $this->makeCandidate();

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

        $stats = (new AutomodService(null, $this->loki, $lockdown))->process();

        $this->assertSame(1, $stats['reviewed'], 'the review under way when the press landed completes; the next is not started');
        Http::assertSentCount(1);
    }
}
