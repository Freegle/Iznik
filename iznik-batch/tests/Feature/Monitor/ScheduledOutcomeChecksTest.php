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
}
