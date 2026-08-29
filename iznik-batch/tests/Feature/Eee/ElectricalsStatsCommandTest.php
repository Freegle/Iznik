<?php

namespace Tests\Feature\Eee;

use App\Services\ElectricalsStatsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the storage half of electricals:stats — the build itself is covered by
 * ElectricalsStatsServiceTest, so the service is stubbed to a tiny fixed payload here.
 */
class ElectricalsStatsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('electricals_stats')->delete();

        $stats = $this->createMock(ElectricalsStatsService::class);
        $stats->method('build')->willReturn([
            'counts' => ['classified' => 4, 'electrical' => 2, 'electrical_pct' => 50.0],
            'impact' => ['tonnes' => 1.2],
        ]);
        $this->instance(ElectricalsStatsService::class, $stats);
    }

    public function test_stores_the_payload_as_valid_json(): void
    {
        $this->artisan('electricals:stats')->assertExitCode(0);

        $row = DB::table('electricals_stats')->orderByDesc('id')->first();

        $this->assertNotNull($row);
        $decoded = json_decode($row->payload, true);
        $this->assertSame(2, $decoded['counts']['electrical']);
    }

    public function test_dry_run_stores_nothing(): void
    {
        $this->artisan('electricals:stats', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('electricals_stats')->count());
    }

    public function test_prune_keeps_only_the_newest_generations(): void
    {
        foreach (range(1, 5) as $i) {
            DB::table('electricals_stats')->insert([
                'generated_at' => now()->subDays(6 - $i)->toDateTimeString(),
                'payload'      => '{"n":' . $i . '}',
            ]);
        }

        $this->artisan('electricals:stats', ['--keep' => 3])->assertExitCode(0);

        $rows = DB::table('electricals_stats')->orderBy('id')->get();

        $this->assertCount(3, $rows, 'the new row plus the two newest old ones');
        // Decoded, not string-compared: the JSON column type normalises whitespace.
        $this->assertSame(4, json_decode($rows[0]->payload)->n);
        $this->assertSame(5, json_decode($rows[1]->payload)->n);
    }
}
