<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Services\StatsGenerationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers StatsGenerationService::regenerateWeightForRange() — the fast
 * Weight-only backfill path added in dca97b020. The generate() method's
 * per-group tests live in StatsGenerationServiceTest.php; this file focuses
 * exclusively on the new batch-per-date code path.
 *
 * The query joins messages_outcomes → messages_groups → messages_items →
 * items. A message without a messages_items row is EXCLUDED (INNER JOIN).
 * Item weight = 0 / null falls back to the population-weighted average.
 */
class StatsRegenerateWeightServiceTest extends TestCase
{
    private StatsGenerationService $service;

    // Fixed past date so no accidentally-seeded test data bleeds in.
    private string $date = '2025-11-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatsGenerationService();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Insert an item with a given weight (or null) and return its id.
     */
    private function createItem(?float $weight, float $popularity = 1.0): int
    {
        return DB::table('items')->insertGetId([
            'name'       => 'TestItem_' . uniqid(),
            'weight'     => $weight,
            'popularity' => $popularity,
        ]);
    }

    /**
     * Link a message to an item via messages_items.
     */
    private function linkMessageToItem(int $msgId, int $itemId): void
    {
        DB::table('messages_items')->insert([
            'msgid'  => $msgId,
            'itemid' => $itemId,
        ]);
    }

    /**
     * Insert an outcome row for a message on the given timestamp.
     */
    private function insertOutcome(int $msgId, string $timestamp, string $outcome = Message::OUTCOME_TAKEN): void
    {
        DB::table('messages_outcomes')->insert([
            'msgid'     => $msgId,
            'userid'    => null,
            'outcome'   => $outcome,
            'timestamp' => $timestamp,
        ]);
    }

    private function assertWeightStat(int $groupId, string $date, int $expected): void
    {
        $row = DB::table('stats')
            ->where('date', $date)
            ->where('groupid', $groupId)
            ->where('type', StatsGenerationService::TYPE_WEIGHT)
            ->first();

        $this->assertNotNull($row, "Expected Weight stat for group {$groupId} on {$date}");
        $this->assertEquals($expected, (int) $row->count, "Weight count mismatch for group {$groupId} on {$date}");
    }

    private function assertNoWeightStat(int $groupId, string $date): void
    {
        $row = DB::table('stats')
            ->where('date', $date)
            ->where('groupid', $groupId)
            ->where('type', StatsGenerationService::TYPE_WEIGHT)
            ->first();

        $this->assertNull($row, "Did not expect a Weight stat for group {$groupId} on {$date}");
    }

    // ── Return shape ───────────────────────────────────────────────────────

    public function test_returns_dates_processed_and_rows_written_keys(): void
    {
        $result = $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertArrayHasKey('datesProcessed', $result);
        $this->assertArrayHasKey('rowsWritten', $result);
    }

    // ── Empty / no-data cases ──────────────────────────────────────────────

    public function test_no_data_returns_zero_rows_written(): void
    {
        $result = $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertSame(1, $result['datesProcessed']);
        $this->assertSame(0, $result['rowsWritten']);
    }

    public function test_message_without_messages_items_is_excluded(): void
    {
        // INNER JOIN on messages_items: message with no item link should be ignored.
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $msg   = $this->createTestMessage($user, $group);

        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');
        // Deliberately NOT linking to any item.

        $result = $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertSame(0, $result['rowsWritten']);
        $this->assertNoWeightStat($group->id, $this->date);
    }

    // ── Known item weight ──────────────────────────────────────────────────

    public function test_known_item_weight_is_used(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: 5.0);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertWeightStat($group->id, $this->date, 5);
    }

    public function test_outcome_received_is_included(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: 3.0);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00', Message::OUTCOME_RECEIVED);

        $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertWeightStat($group->id, $this->date, 3);
    }

    // ── avgWeight fallback ─────────────────────────────────────────────────

    /**
     * @dataProvider nullOrZeroWeightProvider
     */
    public function test_null_or_zero_item_weight_falls_back_to_average(
        ?float $itemWeight,
    ): void {
        // Seed one item with a known weight to establish the average.
        $this->createItem(weight: 10.0, popularity: 1.0);

        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: $itemWeight, popularity: 1.0);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $this->service->regenerateWeightForRange($this->date, $this->date);

        // Average is computed once from ALL items at call time; the only
        // non-null non-zero item has weight=10, popularity=1 → avg = 10.
        // The null/zero item falls back to avg=10.
        $this->assertWeightStat($group->id, $this->date, 10);
    }

    public static function nullOrZeroWeightProvider(): array
    {
        return [
            'null weight'  => [null],
            'zero weight'  => [0.0],
        ];
    }

    public function test_no_items_with_weight_uses_zero_average(): void
    {
        // All items have null weight → avg = 0 → outcome contributes 0 weight.
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: null);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $result = $this->service->regenerateWeightForRange($this->date, $this->date);

        // Weight rounds to 0 → no row written (same as generate()).
        $this->assertSame(0, $result['rowsWritten']);
        $this->assertNoWeightStat($group->id, $this->date);
    }

    // ── Multiple outcomes / items ──────────────────────────────────────────

    public function test_multiple_messages_in_group_sum_correctly(): void
    {
        $group   = $this->createTestGroup();
        $user    = $this->createTestUser();
        $msg1    = $this->createTestMessage($user, $group);
        $msg2    = $this->createTestMessage($user, $group);
        $item1Id = $this->createItem(weight: 4.0);
        $item2Id = $this->createItem(weight: 6.0);
        $this->linkMessageToItem($msg1->id, $item1Id);
        $this->linkMessageToItem($msg2->id, $item2Id);
        $this->insertOutcome($msg1->id, $this->date . ' 09:00:00');
        $this->insertOutcome($msg2->id, $this->date . ' 10:00:00');

        $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertWeightStat($group->id, $this->date, 10);
    }

    public function test_message_linked_to_multiple_items_sums_all_weights(): void
    {
        // A single outcome for a message with two item links contributes
        // both weights (the DISTINCT subquery preserves this because the
        // (msgid, itemid) tuples are different).
        $group   = $this->createTestGroup();
        $user    = $this->createTestUser();
        $msg     = $this->createTestMessage($user, $group);
        $item1Id = $this->createItem(weight: 2.0);
        $item2Id = $this->createItem(weight: 3.0);
        $this->linkMessageToItem($msg->id, $item1Id);
        $this->linkMessageToItem($msg->id, $item2Id);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertWeightStat($group->id, $this->date, 5);
    }

    // ── Multiple groups ────────────────────────────────────────────────────

    public function test_multiple_groups_processed_in_one_date_pass(): void
    {
        $user   = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $msg1    = $this->createTestMessage($user, $group1);
        $msg2    = $this->createTestMessage($user, $group2);
        $item1Id = $this->createItem(weight: 8.0);
        $item2Id = $this->createItem(weight: 12.0);
        $this->linkMessageToItem($msg1->id, $item1Id);
        $this->linkMessageToItem($msg2->id, $item2Id);
        $this->insertOutcome($msg1->id, $this->date . ' 10:00:00');
        $this->insertOutcome($msg2->id, $this->date . ' 11:00:00');

        $result = $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertSame(2, $result['rowsWritten']);
        $this->assertWeightStat($group1->id, $this->date, 8);
        $this->assertWeightStat($group2->id, $this->date, 12);
    }

    // ── Date range ─────────────────────────────────────────────────────────

    /**
     * @dataProvider dateRangeProvider
     */
    public function test_date_range_processes_expected_number_of_dates(
        string $from,
        string $to,
        int $expectedDates,
    ): void {
        $result = $this->service->regenerateWeightForRange($from, $to);

        $this->assertSame($expectedDates, $result['datesProcessed']);
    }

    public static function dateRangeProvider(): array
    {
        return [
            'single day'  => ['2025-11-15', '2025-11-15', 1],
            'three days'  => ['2025-11-13', '2025-11-15', 3],
            'seven days'  => ['2025-11-09', '2025-11-15', 7],
        ];
    }

    public function test_outcomes_are_partitioned_to_the_correct_date(): void
    {
        $group   = $this->createTestGroup();
        $user    = $this->createTestUser();
        $msg1    = $this->createTestMessage($user, $group);
        $msg2    = $this->createTestMessage($user, $group);
        $itemId  = $this->createItem(weight: 7.0);
        $this->linkMessageToItem($msg1->id, $itemId);
        $this->linkMessageToItem($msg2->id, $itemId);

        $date1 = '2025-11-14';
        $date2 = '2025-11-15';

        $this->insertOutcome($msg1->id, $date1 . ' 09:00:00');
        $this->insertOutcome($msg2->id, $date2 . ' 09:00:00');

        $this->service->regenerateWeightForRange($date1, $date2);

        $this->assertWeightStat($group->id, $date1, 7);
        $this->assertWeightStat($group->id, $date2, 7);
    }

    public function test_outcomes_outside_range_are_not_included(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: 5.0);
        $this->linkMessageToItem($msg->id, $itemId);
        // Outcome one day BEFORE the range.
        $this->insertOutcome($msg->id, '2025-11-14 23:59:00');

        $this->service->regenerateWeightForRange($this->date, $this->date);

        $this->assertNoWeightStat($group->id, $this->date);
    }

    // ── Idempotency ────────────────────────────────────────────────────────

    public function test_running_twice_replaces_not_duplicates(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: 5.0);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $this->service->regenerateWeightForRange($this->date, $this->date);
        $this->service->regenerateWeightForRange($this->date, $this->date);

        $count = DB::table('stats')
            ->where('date', $this->date)
            ->where('groupid', $group->id)
            ->where('type', StatsGenerationService::TYPE_WEIGHT)
            ->count();

        $this->assertSame(1, $count, 'REPLACE should leave exactly one Weight row');
    }

    // ── Dry run ─────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_write_to_stats(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: 5.0);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $result = $this->service->regenerateWeightForRange($this->date, $this->date, dryRun: true);

        $this->assertSame(1, $result['datesProcessed']);
        $this->assertSame(1, $result['rowsWritten'], 'dry-run should count rows, not write them');
        $this->assertNoWeightStat($group->id, $this->date);
    }

    public function test_dry_run_excludes_zero_weight_rows_from_count(): void
    {
        // A message whose item has null weight AND no population average → rounds to 0.
        // Dry-run should NOT count this row (matches the non-zero filter).
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: null);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $result = $this->service->regenerateWeightForRange($this->date, $this->date, dryRun: true);

        $this->assertSame(0, $result['rowsWritten']);
    }

    // ── Command integration ────────────────────────────────────────────────

    public function test_command_requires_from_option(): void
    {
        $this->artisan('stats:regenerate-weights')
            ->assertExitCode(1);
    }

    public function test_command_runs_single_date_successfully(): void
    {
        $this->artisan('stats:regenerate-weights', [
            '--from' => $this->date,
            '--to'   => $this->date,
        ])
            ->expectsOutputToContain('Regenerating')
            ->expectsOutputToContain('Done:')
            ->assertExitCode(0);
    }

    public function test_command_dry_run_shows_would_regenerate(): void
    {
        $this->artisan('stats:regenerate-weights', [
            '--from'     => $this->date,
            '--to'       => $this->date,
            '--dry-run'  => true,
        ])
            ->expectsOutputToContain('Would regenerate')
            ->expectsOutputToContain('would be written')
            ->assertExitCode(0);
    }

    public function test_command_writes_weight_stat_via_service(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTestUser();
        $msg    = $this->createTestMessage($user, $group);
        $itemId = $this->createItem(weight: 9.0);
        $this->linkMessageToItem($msg->id, $itemId);
        $this->insertOutcome($msg->id, $this->date . ' 10:00:00');

        $this->artisan('stats:regenerate-weights', [
            '--from' => $this->date,
            '--to'   => $this->date,
        ])->assertExitCode(0);

        $this->assertWeightStat($group->id, $this->date, 9);
    }
}
