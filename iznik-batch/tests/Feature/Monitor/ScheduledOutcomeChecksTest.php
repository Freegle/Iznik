<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\Checks\BacklogCheck;
use App\Monitoring\Checks\CallbackCheck;
use App\Monitoring\Checks\FreshnessCheck;
use App\Monitoring\Checks\ProducedSinceCheck;
use App\Monitoring\OutcomeResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the outcome-monitoring check primitives.
 *
 * Each primitive is exercised against a real (migrated) table so a wrong
 * table/column name would surface as a query error, not a silent pass:
 *  - FreshnessCheck       — max(timestamp) must be within a max age.
 *  - ProducedSinceCheck   — count of rows since a window start must meet a floor.
 *  - BacklogCheck         — pending rows older than a max age must not pile up.
 *  - CallbackCheck        — arbitrary closure result.
 *
 * Plus the cross-cutting base behaviours: an unmet precondition or a time
 * outside the active window yields SKIPPED (never a breach).
 *
 * FK-free tables (spam_whitelist_ips, background_tasks) are used so the tests
 * don't depend on seeding parent rows. DatabaseTransactions rolls everything
 * back; setUp clears the tables so "0 rows" assertions are deterministic.
 */
class ScheduledOutcomeChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('spam_whitelist_ips')->delete();
        DB::table('background_tasks')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedWhitelistIp(string $comment, Carbon $date): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254),
            'comment' => $comment,
            'date' => $date,
        ]);
    }

    private function seedBackgroundTask(Carbon $createdAt, ?Carbon $processedAt = null, ?Carbon $failedAt = null, int $attempts = 0): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notification',
            'data' => json_encode(['x' => 1]),
            'created_at' => $createdAt,
            'processed_at' => $processedAt,
            'failed_at' => $failedAt,
            'attempts' => $attempts,
        ]);
    }

    // --- FreshnessCheck -----------------------------------------------------

    public function test_freshness_check_ok_when_recent(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        $this->seedWhitelistIp('UK mobile: EE', Carbon::now()->subDays(2));

        $check = new FreshnessCheck('test:fresh', 'spam_whitelist_ips', 'date', 40 * 24 * 60);
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_freshness_check_breaches_when_stale(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        $this->seedWhitelistIp('UK mobile: EE', Carbon::now()->subDays(50));

        $check = new FreshnessCheck('test:fresh', 'spam_whitelist_ips', 'date', 40 * 24 * 60);
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
    }

    public function test_freshness_check_breaches_when_no_rows(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));

        $check = new FreshnessCheck('test:fresh', 'spam_whitelist_ips', 'date', 40 * 24 * 60);
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
        $this->assertStringContainsString('no rows', $result->message);
    }

    public function test_freshness_check_honours_where_filter(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        // A fresh row that does NOT match the filter, and a stale one that does.
        $this->seedWhitelistIp('Some other entry', Carbon::now()->subDays(1));
        $this->seedWhitelistIp('UK mobile: EE', Carbon::now()->subDays(50));

        $check = new FreshnessCheck(
            'test:fresh',
            'spam_whitelist_ips',
            'date',
            40 * 24 * 60,
            fn ($q) => $q->where('comment', 'like', 'UK mobile:%'),
        );
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
    }

    // --- ProducedSinceCheck -------------------------------------------------

    public function test_produced_since_ok_when_floor_met(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        $this->seedBackgroundTask(Carbon::now()->subHours(2));
        $this->seedBackgroundTask(Carbon::now()->subHour());

        $check = new ProducedSinceCheck(
            'test:produced',
            'background_tasks',
            'created_at',
            fn ($now) => $now->copy()->startOfDay(),
            1,
        );
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_produced_since_breaches_when_below_floor(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        // Only a row from yesterday — before today's window start.
        $this->seedBackgroundTask(Carbon::now()->subDay());

        $check = new ProducedSinceCheck(
            'test:produced',
            'background_tasks',
            'created_at',
            fn ($now) => $now->copy()->startOfDay(),
            1,
        );
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
    }

    // --- BacklogCheck -------------------------------------------------------

    public function test_backlog_check_ok_when_no_stale_pending(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        // Pending but recent (2 min old) — not yet stale.
        $this->seedBackgroundTask(Carbon::now()->subMinutes(2));
        // Old but already processed — must be ignored.
        $this->seedBackgroundTask(Carbon::now()->subHour(), processedAt: Carbon::now()->subMinutes(30));

        $check = new BacklogCheck(
            'test:backlog',
            'background_tasks',
            'created_at',
            10,
            fn ($q) => $q->whereNull('processed_at')->whereNull('failed_at')->where('attempts', '<', 3),
        );
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_backlog_check_breaches_when_stale_pending(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        // Pending and old (20 min) — worker appears stuck.
        $this->seedBackgroundTask(Carbon::now()->subMinutes(20));

        $check = new BacklogCheck(
            'test:backlog',
            'background_tasks',
            'created_at',
            10,
            fn ($q) => $q->whereNull('processed_at')->whereNull('failed_at')->where('attempts', '<', 3),
        );
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
    }

    // --- CallbackCheck ------------------------------------------------------

    public function test_callback_check_returns_callback_result(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));

        $check = new CallbackCheck(
            'test:callback',
            fn ($now) => OutcomeResult::breach('test:callback', 'boom'),
        );
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach());
        $this->assertSame('boom', $result->message);
    }

    // --- Base behaviours ----------------------------------------------------

    public function test_unmet_precondition_is_skipped_not_breached(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 10, 0, 0));
        // No rows at all would normally breach, but the precondition is false.
        $check = (new FreshnessCheck('test:fresh', 'spam_whitelist_ips', 'date', 60))
            ->enabledWhen(fn () => false);
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isSkipped(), $result->message);
    }

    public function test_outside_active_window_is_skipped(): void
    {
        // 03:00 UTC — outside a 06:00-12:00 window.
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 3, 0, 0));

        $check = (new FreshnessCheck('test:fresh', 'spam_whitelist_ips', 'date', 60))
            ->activeBetween(6, 12, 'UTC');
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isSkipped(), $result->message);
    }

    public function test_inside_active_window_is_evaluated(): void
    {
        // 09:00 UTC — inside a 06:00-12:00 window; no rows → breach (evaluated).
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 9, 0, 0));

        $check = (new FreshnessCheck('test:fresh', 'spam_whitelist_ips', 'date', 60))
            ->activeBetween(6, 12, 'UTC');
        $result = $check->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
    }

    /**
     * The daily-stats coverage check. Its companion ProducedSinceCheck also has a floor of 1,
     * so it passes whenever the 02:30 run wrote a single row — against a real ~4,300 a day
     * covering all 507 communities. Coverage is the assertion worth making, and the expected
     * number comes from the groups table rather than from a guess.
     */
    private function statsCoverageCheck(): \App\Monitoring\OutcomeCheck
    {
        foreach ((new \App\Monitoring\ScheduledOutcomeRegistry())->checks() as $check) {
            if ($check->slug() === 'stats:generate-daily coverage') {
                return $check;
            }
        }

        $this->fail('the daily-stats coverage check is not registered');
    }

    /** Give every group a stats row for $day, and return the ids seeded. */
    private function seedStatsForAllGroups(string $day): array
    {
        $ids = DB::table('groups')->pluck('id')->all();

        foreach ($ids as $id) {
            DB::table('stats')->insert([
                'date' => $day,
                'end' => $day,
                'groupid' => $id,
                'type' => 'ApprovedMessageCount',
                'count' => 1,
            ]);
        }

        return $ids;
    }

    public function test_stats_coverage_ok_when_every_community_is_covered(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 7, 0, 0, 'Europe/London'));
        $this->seedStatsForAllGroups('2026-09-14');

        $result = $this->statsCoverageCheck()->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_stats_coverage_breaches_when_a_community_is_missed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 7, 0, 0, 'Europe/London'));
        $ids = $this->seedStatsForAllGroups('2026-09-14');
        $this->assertNotEmpty($ids, 'there are groups to cover');

        // One community's rows go missing — the 02:30 run stopped short. A floor of 1 cannot
        // see that; coverage can.
        DB::table('stats')->where('date', '2026-09-14')->where('groupid', end($ids))->delete();

        $result = $this->statsCoverageCheck()->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
        $this->assertStringContainsString('1 missing', $result->message);
    }

    public function test_stats_coverage_ignores_a_community_founded_after_the_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 7, 0, 0, 'Europe/London'));
        $this->seedStatsForAllGroups('2026-09-14');

        // Founded after the day being checked, so it cannot have stats for it and must not
        // count against coverage — otherwise every new community turns the check red for a day.
        $new = $this->createTestGroup();
        DB::table('groups')->where('id', $new->id)->update(['founded' => '2026-09-15 09:00:00']);

        $result = $this->statsCoverageCheck()->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    /**
     * The daily-digest window check. Its companion ProducedSinceCheck has a floor of 1, so it
     * passes on any day at least one digest went out — which is why the 2026-09-15..17
     * collapse (throughput down 13x, digests landing at 01:00, 40,000+ still sent) passed
     * three days running. This one asserts the run FINISHED, not that it happened.
     */
    private function registeredCheck(string $slug): \App\Monitoring\OutcomeCheck
    {
        foreach ((new \App\Monitoring\ScheduledOutcomeRegistry())->checks() as $check) {
            if ($check->slug() === $slug) {
                return $check;
            }
        }

        $this->fail("the check '{$slug}' is not registered");
    }

    private function digestWindowCheck(): \App\Monitoring\OutcomeCheck
    {
        return $this->registeredCheck('mail:digest:unified --mode=daily window');
    }

    /**
     * push:daily-posts reads the SAME getPostsForUser() as the daily digest, so it shares the
     * failure mode: if that query slows down again, this overruns too and members get push
     * notifications at odd hours. It keeps its own cursor row (mode='push').
     */
    public function test_the_push_window_check_is_registered_and_scoped_to_its_own_cursor(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 13, 0, 0, 'Europe/London'));
        config([
            'freegle.posts_push_allowlist' => '*',
            'freegle.digest.daily_allowlist' => '*',
        ]);

        // A DIGEST send after the window must not trip the PUSH check - the two share a table
        // and are told apart only by the mode column.
        $userid = DB::table('users')->insertGetId([
            'firstname' => 'Push', 'lastname' => 'Window', 'added' => now(),
        ]);
        DB::table('users_digests')->insert([
            'userid' => $userid, 'mode' => 'daily', 'lastsent' => '2026-09-15 12:30:00',
        ]);

        $this->assertTrue(
            $this->registeredCheck('push:daily-posts window')->evaluate(Carbon::now())->isOk(),
            'a late DAILY send must not be reported against the PUSH window'
        );

        // ...and a late push send does trip it.
        $pushUser = DB::table('users')->insertGetId([
            'firstname' => 'Push2', 'lastname' => 'Window', 'added' => now(),
        ]);
        DB::table('users_digests')->insert([
            'userid' => $pushUser, 'mode' => 'push', 'lastsent' => '2026-09-15 12:30:00',
        ]);

        $result = $this->registeredCheck('push:daily-posts window')->evaluate(Carbon::now());
        $this->assertTrue($result->isBreach(), $result->message);
        $this->assertStringContainsString('overran', $result->message);
    }

    private function seedDailySend(string $sentAtUtc): void
    {
        $userid = DB::table('users')->insertGetId([
            'firstname' => 'Digest',
            'lastname' => 'Window',
            'added' => now(),
        ]);

        DB::table('users_digests')->insert([
            'userid' => $userid,
            'mode' => 'daily',
            'lastsent' => $sentAtUtc,
        ]);
    }

    public function test_digest_window_check_is_quiet_when_the_run_finished_inside_its_window(): void
    {
        // 12:00 London on a BST day is 11:00 UTC. A healthy run's last send lands just inside
        // it — 09-13 and 09-14 both ended at 11:59 London.
        Carbon::setTestNow(Carbon::create(2026, 9, 14, 13, 0, 0, 'Europe/London'));
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->seedDailySend('2026-09-14 10:59:00');

        $result = $this->digestWindowCheck()->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_digest_window_check_breaches_when_the_run_overran(): void
    {
        // 09-15, the first broken day: sends carried on past the window and into the night.
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 13, 0, 0, 'Europe/London'));
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->seedDailySend('2026-09-15 10:59:00');   // inside the window — must not count
        $this->seedDailySend('2026-09-15 12:30:00');   // after it — must count

        $result = $this->digestWindowCheck()->evaluate(Carbon::now());

        $this->assertTrue($result->isBreach(), $result->message);
        $this->assertStringContainsString('overran', $result->message);
    }

    public function test_digest_window_check_ignores_yesterdays_late_sends(): void
    {
        // lastsent holds only each member's most recent send. Someone sent late YESTERDAY must
        // not keep the check red today, or one bad day latches it on for good.
        Carbon::setTestNow(Carbon::create(2026, 9, 16, 13, 0, 0, 'Europe/London'));
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->seedDailySend('2026-09-15 23:30:00');

        $result = $this->digestWindowCheck()->evaluate(Carbon::now());

        $this->assertTrue($result->isOk(), $result->message);
    }
}
