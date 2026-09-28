<?php

namespace Tests\Unit\Services\Lockdown;

use App\Models\User;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownTriageService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LockdownTriageService::releaseChitChatHolds() (plan 2026-09-27-lockdown-switch.md,
 * section 11.4). The hold row and the newsfeed post's hidden timestamp are both written
 * by the Go API when a post lands during an incident (newsfeed.go createPost, create.go);
 * this only tests the batch-side lift: spam_marked holds are deleted on the spot whatever
 * chitchat's held state, low-risk holds are unhidden once chitchat is no longer held, and
 * risky holds are left exactly as Go set them for a moderator to decide.
 */
class LockdownTriageServiceChitChatTest extends TestCase
{
    private LockdownService $lockdown;

    private LockdownTriageService $triage;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_holds')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->triage = new LockdownTriageService($this->lockdown);
    }

    private function makeNewsfeedPost(User $user, bool $hidden): int
    {
        $srid = (int) config('freegle.srid', 3857);

        return (int) DB::table('newsfeed')->insertGetId([
            'type' => 'Message',
            'userid' => $user->id,
            'message' => 'Test chitchat post',
            'added' => now(),
            'timestamp' => now(),
            'hidden' => $hidden ? now() : null,
            'hiddenby' => $hidden ? $user->id : null,
            'position' => DB::raw("ST_GeomFromText('POINT(0 0)', $srid)"),
        ]);
    }

    private function makeHold(int $refid, ?string $risk, ?string $outcome): int
    {
        $lockdownId = $this->lockdown->current()->id;

        return (int) DB::table('lockdown_holds')->insertGetId([
            'lockdownid' => $lockdownId,
            'kind' => LockdownTriageService::KIND_CHITCHAT,
            'refid' => $refid,
            'risk' => $risk,
            'outcome' => $outcome,
        ]);
    }

    public function test_spam_marked_hold_deletes_newsfeed_post_and_marks_rejected_regardless_of_held_state(): void
    {
        $this->lockdown->press(null, 'test');
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user, true);
        $holdId = $this->makeHold($nfid, LockdownTriageService::RISK_SPAM, 'spam_marked');

        $stats = $this->triage->releaseChitChatHolds();

        $this->assertSame(1, $stats['rejected']);
        $this->assertNull(DB::table('newsfeed')->where('id', $nfid)->first());
        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertSame('rejected', $hold->outcome);
        $this->assertNotNull($hold->releasedat);
    }

    public function test_low_risk_hold_released_once_chitchat_is_lifted(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['chitchat' => false], null);
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user, true);
        $holdId = $this->makeHold($nfid, LockdownTriageService::RISK_LOW, null);

        $stats = $this->triage->releaseChitChatHolds();

        $this->assertSame(1, $stats['released']);
        $nf = DB::table('newsfeed')->where('id', $nfid)->first();
        $this->assertNull($nf->hidden, 'released post is unhidden');
        $this->assertNull($nf->hiddenby);
        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertSame('released', $hold->outcome);
        $this->assertNotNull($hold->releasedat);
    }

    public function test_low_risk_hold_left_alone_while_chitchat_still_held(): void
    {
        $this->lockdown->press(null, 'test');
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user, true);
        $holdId = $this->makeHold($nfid, LockdownTriageService::RISK_LOW, null);

        $stats = $this->triage->releaseChitChatHolds();

        $this->assertSame(0, $stats['released']);
        $nf = DB::table('newsfeed')->where('id', $nfid)->first();
        $this->assertNotNull($nf->hidden, 'still held, so still hidden');
        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertNull($hold->outcome);
    }

    public function test_risky_hold_left_for_a_moderator_even_once_chitchat_is_lifted(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['chitchat' => false], null);
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user, true);
        $holdId = $this->makeHold($nfid, LockdownTriageService::RISK_RISKY, null);

        $stats = $this->triage->releaseChitChatHolds();

        $this->assertSame(0, $stats['released']);
        $this->assertSame(0, $stats['rejected']);
        $nf = DB::table('newsfeed')->where('id', $nfid)->first();
        $this->assertNotNull($nf->hidden, 'risky holds stay hidden for a moderator to review');
        $hold = DB::table('lockdown_holds')->where('id', $holdId)->first();
        $this->assertNull($hold->outcome);
    }

    // --- Mid-run press (section 11.6 point 2) ---

    public function test_second_low_risk_hold_left_alone_once_pressed_mid_batch(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['chitchat' => false], null);
        $user = $this->createTestUser();
        $nfid1 = $this->makeNewsfeedPost($user, true);
        $this->makeHold($nfid1, LockdownTriageService::RISK_LOW, null);
        $nfid2 = $this->makeNewsfeedPost($user, true);
        $holdId2 = $this->makeHold($nfid2, LockdownTriageService::RISK_LOW, null);

        // Presses chitchat back on as a side effect of the first hold's held() check
        // succeeding, simulating another process pressing the switch while this batch is
        // already under way. Holds release oldest id first, so hold 1 is checked first.
        // Call #1 is the top-of-method guard (not held, chitchat already lifted above);
        // call #2 is the first hold's per-item check.
        $lockdown = new class extends LockdownService {
            public int $calls = 0;

            public function held(string $surface): bool
            {
                $this->calls++;
                $result = parent::held($surface);
                if ($this->calls === 2) {
                    $this->setSurfaces(['chitchat' => true], null);
                }

                return $result;
            }
        };
        $triage = new LockdownTriageService($lockdown);

        $stats = $triage->releaseChitChatHolds();

        $this->assertSame(1, $stats['released'], 'the hold already under way when the press landed still completes');

        $nf1 = DB::table('newsfeed')->where('id', $nfid1)->first();
        $this->assertNull($nf1->hidden, 'first hold released');

        $nf2 = DB::table('newsfeed')->where('id', $nfid2)->first();
        $this->assertNotNull($nf2->hidden, 'second hold held at its next item, not waiting for the next run');
        $hold2 = DB::table('lockdown_holds')->where('id', $holdId2)->first();
        $this->assertNull($hold2->outcome);
    }
}
