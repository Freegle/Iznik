<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\OutcomeCheck;
use App\Monitoring\ScheduledOutcomeRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The embedding pipeline fails silently: nothing throws when embeddings:generate falls behind,
 * the posts just stop appearing in vector search. These two registry checks watch it from
 * outside, on the same predicate the command uses (live = messages_spatial successful=0,
 * promised=0; embedded = a messages_embeddings row).
 */
class EmbeddingCoverageMonitoringTest extends TestCase
{
    private $user;

    private $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 9, 12, 0, 0));
        DB::table('messages_embeddings')->delete();
        DB::table('messages_spatial')->delete();
        $this->user = $this->createTestUser();
        $this->group = $this->createTestGroup();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(string $slug): OutcomeCheck
    {
        foreach ((new ScheduledOutcomeRegistry())->checks() as $check) {
            if ($check->slug() === $slug) {
                return $check;
            }
        }

        $this->fail("check '{$slug}' not found in registry");
    }

    private function seedPost(Carbon $arrival, bool $embedded, int $successful = 0, int $promised = 0): int
    {
        $message = $this->createTestMessage($this->user, $this->group, ['arrival' => $arrival]);

        DB::statement(
            'INSERT INTO messages_spatial (msgid, groupid, msgtype, successful, promised, arrival, point)
             VALUES (?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 3857))',
            [$message->id, $this->group->id, 'Offer', $successful, $promised, $arrival, 'POINT(0 0)']
        );

        if ($embedded) {
            DB::statement(
                'INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)',
                [$message->id, str_repeat("\0", 1024), 'test']
            );
        }

        return $message->id;
    }

    public function test_coverage_skipped_when_there_are_no_live_posts(): void
    {
        $result = $this->check('embeddings:generate coverage')->evaluate(Carbon::now());

        $this->assertTrue($result->isSkipped(), $result->message);
    }

    public function test_coverage_ok_at_healthy_level(): void
    {
        config(['freegle.monitoring.embeddings_min_coverage_percent' => 90]);
        for ($i = 0; $i < 9; $i++) {
            $this->seedPost(Carbon::now()->subDays(2), true);
        }
        $this->seedPost(Carbon::now()->subDays(2), false);

        $result = $this->check('embeddings:generate coverage')->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
        $this->assertStringContainsString('90%', $result->message);
    }

    public function test_coverage_breaches_below_the_configured_floor(): void
    {
        config(['freegle.monitoring.embeddings_min_coverage_percent' => 95]);
        for ($i = 0; $i < 9; $i++) {
            $this->seedPost(Carbon::now()->subDays(2), true);
        }
        $this->seedPost(Carbon::now()->subDays(2), false);

        $result = $this->check('embeddings:generate coverage')->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
        $this->assertStringContainsString('1 missing', $result->message);
    }

    public function test_coverage_ignores_posts_that_are_taken_or_promised(): void
    {
        config(['freegle.monitoring.embeddings_min_coverage_percent' => 99]);
        $this->seedPost(Carbon::now()->subDays(2), true);
        $this->seedPost(Carbon::now()->subDays(2), false, 1, 0);
        $this->seedPost(Carbon::now()->subDays(2), false, 0, 1);

        $result = $this->check('embeddings:generate coverage')->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_lag_ok_when_new_posts_are_inside_the_grace_period(): void
    {
        config(['freegle.monitoring.embeddings_lag_threshold' => 0]);
        $this->seedPost(Carbon::now()->subMinutes(10), false);

        $result = $this->check('embeddings:generate')->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_lag_breaches_when_recent_posts_stay_unembedded(): void
    {
        config(['freegle.monitoring.embeddings_lag_threshold' => 1]);
        $this->seedPost(Carbon::now()->subHour(), false);
        $this->seedPost(Carbon::now()->subHours(2), false);

        $result = $this->check('embeddings:generate')->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
    }

    public function test_lag_tolerates_up_to_the_threshold(): void
    {
        config(['freegle.monitoring.embeddings_lag_threshold' => 2]);
        $this->seedPost(Carbon::now()->subHour(), false);
        $this->seedPost(Carbon::now()->subHours(2), false);

        $result = $this->check('embeddings:generate')->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_lag_ignores_posts_older_than_the_lookback_and_embedded_posts(): void
    {
        config(['freegle.monitoring.embeddings_lag_threshold' => 0]);
        // A permanent straggler from last week must not hold the check red.
        $this->seedPost(Carbon::now()->subDays(7), false);
        $this->seedPost(Carbon::now()->subHour(), true);

        $result = $this->check('embeddings:generate')->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }
}
