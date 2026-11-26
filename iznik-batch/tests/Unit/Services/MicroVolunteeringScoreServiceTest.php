<?php

namespace Tests\Unit\Services;

use App\Services\MicroVolunteeringScoreService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MicroVolunteeringScoreServiceTest extends TestCase
{
    protected MicroVolunteeringScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MicroVolunteeringScoreService();
        DB::table('microactions')->delete();
    }

    public function test_empty_input_returns_zero(): void
    {
        $result = $this->service->scoreAndPromote();

        $this->assertEquals(0, $result['promoted']);
    }

    public function test_scoring_updates_score_positive_on_majority_match(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user1, $group);

        // Three users all say 'Approve' for the same message.
        foreach ([$user1, $user2, $user3] as $user) {
            DB::table('microactions')->insert([
                'userid' => $user->id,
                'msgid' => $message->id,
                'result' => 'Approve',
                'timestamp' => now()->subHours(24),
            ]);
        }

        $this->service->scoreAndPromote();

        // All three agree — majority is 'Approve', so score_positive should be 1 for all.
        $scores = DB::table('microactions')
            ->where('msgid', $message->id)
            ->pluck('score_positive')
            ->all();
        $this->assertNotEmpty($scores);
        foreach ($scores as $score) {
            $this->assertEquals(1, $score);
        }
    }

    public function test_scoring_updates_score_negative_on_minority(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user1, $group);

        // user1 says 'Reject', user2 and user3 say 'Approve' — user1 is in the minority.
        DB::table('microactions')->insert([
            'userid' => $user1->id, 'msgid' => $message->id, 'result' => 'Reject',
            'timestamp' => now()->subHours(24),
        ]);
        DB::table('microactions')->insert([
            'userid' => $user2->id, 'msgid' => $message->id, 'result' => 'Approve',
            'timestamp' => now()->subHours(24),
        ]);
        DB::table('microactions')->insert([
            'userid' => $user3->id, 'msgid' => $message->id, 'result' => 'Approve',
            'timestamp' => now()->subHours(24),
        ]);

        $this->service->scoreAndPromote();

        $user1Score = DB::table('microactions')
            ->where('userid', $user1->id)
            ->where('msgid', $message->id)
            ->first();
        $this->assertEquals(0, $user1Score->score_positive);
        $this->assertEquals(1, $user1Score->score_negative);
    }

    public function test_promote_upgrades_accurate_user(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['trustlevel' => 'Basic']);

        // Insert 7+ days of high-accuracy microactions (msgid=null, promote doesn't need real messages).
        for ($i = 0; $i <= 7; $i++) {
            DB::table('microactions')->insert([
                'userid' => $user->id,
                'msgid' => null,
                'result' => 'Approve',
                'score_positive' => 1,
                'score_negative' => 0,
                'timestamp' => now()->subDays($i),
            ]);
        }

        $result = $this->service->scoreAndPromote();

        $trustlevel = DB::table('users')->where('id', $user->id)->value('trustlevel');
        $this->assertEquals('Moderate', $trustlevel);
        $this->assertGreaterThanOrEqual(1, $result['promoted']);
    }

    public function test_promote_skips_user_below_threshold(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['trustlevel' => 'Basic']);

        // Low accuracy: score_negative > score_positive.
        for ($i = 0; $i <= 7; $i++) {
            DB::table('microactions')->insert([
                'userid' => $user->id,
                'msgid' => null,
                'result' => 'Approve',
                'score_positive' => 0,
                'score_negative' => 1,
                'timestamp' => now()->subDays($i),
            ]);
        }

        $this->service->scoreAndPromote();

        $trustlevel = DB::table('users')->where('id', $user->id)->value('trustlevel');
        $this->assertEquals('Basic', $trustlevel);
    }
}
