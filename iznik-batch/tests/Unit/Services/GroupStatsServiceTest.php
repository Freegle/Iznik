<?php

namespace Tests\Unit\Services;

use App\Services\GroupStatsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupStatsServiceTest extends TestCase
{
    protected GroupStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GroupStatsService();
    }

    public function test_leaves_a_community_own_repost_settings_alone(): void
    {
        $group = $this->createTestGroup();

        // Repost settings are system-wide now. A community's own value is ignored, and is not
        // repaired or rewritten either: it stays as it was so the change can be reverted.
        $stored = json_encode(['reposts' => ['interval' => '7', 'max' => '3']]);
        DB::table('groups')->where('id', $group->id)->update(['settings' => $stored]);

        $result = $this->service->updateGroupStats();

        $this->assertSame($stored, DB::table('groups')->where('id', $group->id)->value('settings'));
        $this->assertArrayNotHasKey('reposts_fixed', $result);
    }

    public function test_updates_stats_outcomes(): void
    {
        $group = $this->createTestGroup();

        // Insert stats outcome data.
        DB::table('stats')->insert([
            'date' => now()->subDays(5),
            'end' => now()->subDays(5),
            'groupid' => $group->id,
            'type' => 'Outcomes',
            'count' => 42,
        ]);

        $result = $this->service->updateGroupStats();

        $monthDate = now()->subDays(5)->format('Y') . '-' . str_pad(now()->subDays(5)->format('n'), 2, '0', STR_PAD_LEFT);
        $outcomeCount = DB::table('stats_outcomes')
            ->where('groupid', $group->id)
            ->where('date', $monthDate)
            ->value('count');

        $this->assertEquals(42, $outcomeCount);
        $this->assertGreaterThanOrEqual(1, $result['stats_outcomes_updated']);
    }

    public function test_returns_zero_when_nothing_to_fix(): void
    {
        $result = $this->service->updateGroupStats();

        $this->assertEquals(0, $result['polyindex_fixed']);
    }
}
