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

    public function test_fixes_repost_string_values(): void
    {
        $group = $this->createTestGroup();

        // Set reposts with string values.
        DB::table('groups')->where('id', $group->id)->update([
            'settings' => json_encode(['reposts' => ['interval' => '7', 'max' => '3']]),
        ]);

        $result = $this->service->updateGroupStats();

        $settings = json_decode(DB::table('groups')->where('id', $group->id)->value('settings'), true);
        $this->assertIsInt($settings['reposts']['interval']);
        $this->assertIsInt($settings['reposts']['max']);
        $this->assertGreaterThanOrEqual(1, $result['reposts_fixed']);
    }

    public function test_skips_repost_already_int(): void
    {
        $group = $this->createTestGroup();

        DB::table('groups')->where('id', $group->id)->update([
            'settings' => json_encode(['reposts' => ['interval' => 7, 'max' => 3]]),
        ]);

        $result = $this->service->updateGroupStats();

        $this->assertEquals(0, $result['reposts_fixed']);
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

        $this->assertEquals(0, $result['reposts_fixed']);
    }
}
