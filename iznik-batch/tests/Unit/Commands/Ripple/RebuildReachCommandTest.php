<?php

namespace Tests\Unit\Commands\Ripple;

use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

/**
 * ripple:rebuild-reach is a thin CLI wrapper around ExpandService::backfillReach()
 * (which ExpandServiceTest exercises in depth), so this suite focuses on the
 * command's OWN logic: option validation, the single-instance/per-shard lock,
 * dry-run's exemption from that lock, and the two output formats.
 */
class RebuildReachCommandTest extends TestCase
{
    use SeedsReachCells;

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.ripple.reachable_gate' => true]);
        DB::statement('DELETE FROM rippling_reach');
    }

    private function seedMessage(float $lat = 51.5, float $lng = -0.1): int
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: sofa (London)',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => now()->subMinutes(30),
            'arrival' => now()->subMinutes(30),
            'lat' => $lat,
            'lng' => $lng,
        ]);

        return (int) $message->id;
    }

    /** A candidate row backfillReach() will pick up: status in the active set, reachable_group_ids NULL. */
    private function seedReachRow(int $msgid, float $lat = 51.5, float $lng = -0.1, string $status = 'expanding', int $tick = 1): void
    {
        DB::insert(
            "INSERT INTO rippling_reach
                (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                 status, schedule, next_expansion_at, created_at, updated_at)
             VALUES (?, ?, ?, ?,
                     ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)),
                     ?, 'drive', ?, 3, 90, ?, NULL, NULL, NOW(), NOW())",
            [$msgid, $lat, $lng, $this->reachCellsFor('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))'), now()->subMinutes(30), $tick, $status]
        );
    }

    private function fakeRouting(int $ticks = 3): void
    {
        $polygon = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]],
        ];
        $schedule = [];
        for ($k = 1; $k <= $ticks; $k++) {
            $schedule[] = ['tick' => $k, 'drive_min' => 5.0 * $k, 'cumulative_users' => 30 * $k, 'polygon' => $polygon];
        }
        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 90, 'max_drive_min' => 30, 'schedule' => $schedule,
        ], 200)]);
    }

    private function reachableGroupIds(int $msgid)
    {
        return DB::table('rippling_reach')->where('msgid', $msgid)->value('reachable_group_ids');
    }

    // -- gate config warning ------------------------------------------------

    public function test_warns_when_reachable_gate_config_is_off(): void
    {
        config(['freegle.ripple.reachable_gate' => false]);
        Http::fake();

        $this->artisan('ripple:rebuild-reach', ['--dry-run' => true])
            ->expectsOutputToContain('freegle.ripple.reachable_gate is OFF')
            ->assertExitCode(0);
    }

    public function test_no_gate_warning_when_config_is_on(): void
    {
        Http::fake();

        $this->artisan('ripple:rebuild-reach', ['--dry-run' => true])
            ->doesntExpectOutputToContain('freegle.ripple.reachable_gate is OFF')
            ->assertExitCode(0);
    }

    // -- option validation ----------------------------------------------------

    public static function togetherViolationProvider(): array
    {
        return [
            'shards without shard' => [['--shards' => 2], []],
            'shard without shards' => [[], ['--shard' => 0]],
        ];
    }

    /** @dataProvider togetherViolationProvider */
    public function test_shards_and_shard_must_be_supplied_together(array $shardsOpt, array $shardOpt): void
    {
        $this->artisan('ripple:rebuild-reach', array_merge($shardsOpt, $shardOpt))
            ->expectsOutputToContain('--shards and --shard must be used together.')
            ->assertExitCode(1);
    }

    public static function invalidShardRangeProvider(): array
    {
        return [
            'shards below 2' => [1, 0],
            'shard negative' => [2, -1],
            'shard equals shards' => [2, 2],
        ];
    }

    /** @dataProvider invalidShardRangeProvider */
    public function test_invalid_shard_range_is_rejected(int $shards, int $shard): void
    {
        $this->artisan('ripple:rebuild-reach', ['--shards' => $shards, '--shard' => $shard])
            ->expectsOutputToContain('--shard must be in 0..shards-1 and --shards >= 2.')
            ->assertExitCode(1);
    }

    // -- single-instance lock ---------------------------------------------------

    public function test_concurrent_run_is_blocked_by_the_lock_and_leaves_candidates_untouched(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedMessage();
        $this->seedReachRow($msgid);

        $held = Cache::lock('ripple:rebuild-reach', 3600);
        $this->assertTrue($held->get(), 'precondition: another instance holds the lock');

        try {
            $this->artisan('ripple:rebuild-reach')
                ->expectsOutputToContain('Another ripple:rebuild-reach instance is running; exiting.')
                ->assertExitCode(0);

            $this->assertNull(
                $this->reachableGroupIds($msgid),
                'a locked-out run must not touch the candidate row'
            );
        } finally {
            $held->release();
        }
    }

    public function test_lock_is_released_after_a_normal_run_completes(): void
    {
        $this->fakeRouting();

        $this->artisan('ripple:rebuild-reach')->assertExitCode(0);

        $after = Cache::lock('ripple:rebuild-reach', 3600);
        $this->assertTrue($after->get(), 'lock must be free once the run has finished');
        $after->release();
    }

    public function test_each_shard_has_its_own_independent_lock(): void
    {
        $this->fakeRouting();

        $heldShard0 = Cache::lock('ripple:rebuild-reach:shard2-0', 3600);
        $this->assertTrue($heldShard0->get(), 'precondition: hold shard 0 of 2');

        try {
            // Shard 0's own instance is locked out.
            $this->artisan('ripple:rebuild-reach', ['--shards' => 2, '--shard' => 0])
                ->expectsOutputToContain('Another ripple:rebuild-reach:shard2-0 instance is running; exiting.')
                ->assertExitCode(0);

            // Shard 1 uses a distinct lock name, so it is NOT blocked - it reaches the
            // real summary line rather than the lock warning.
            $this->artisan('ripple:rebuild-reach', ['--shards' => 2, '--shard' => 1])
                ->expectsOutputToContain('Rebuilt 0/0 reach row(s)')
                ->assertExitCode(0);
        } finally {
            $heldShard0->release();
        }
    }

    public function test_dry_run_bypasses_the_lock_entirely(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedMessage();
        $this->seedReachRow($msgid);

        $held = Cache::lock('ripple:rebuild-reach', 3600);
        $this->assertTrue($held->get(), 'another (non-dry-run) instance holds the lock');

        try {
            // Dry-run never calls $lock->get(), so it proceeds despite the held lock.
            $this->artisan('ripple:rebuild-reach', ['--dry-run' => true])
                ->expectsOutputToContain('Would rebuild 1/1 reach row(s)')
                ->assertExitCode(0);
        } finally {
            $held->release();
        }
    }

    // -- dry-run vs normal output + write behaviour ------------------------------

    public function test_dry_run_reports_the_preview_and_writes_nothing(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedMessage();
        $this->seedReachRow($msgid);

        $this->artisan('ripple:rebuild-reach', ['--dry-run' => true])
            ->expectsOutputToContain('Would rebuild 1/1 reach row(s) (0 skipped) and retract ~0 out-of-reach group-copies.')
            ->assertExitCode(0);

        $this->assertNull($this->reachableGroupIds($msgid), 'dry run must not write reachable_group_ids');
    }

    public function test_normal_run_rebuilds_the_row_and_reports_the_summary(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedMessage();
        $this->seedReachRow($msgid);

        $this->artisan('ripple:rebuild-reach')
            ->expectsOutputToContain('Rebuilt 1/1 reach row(s) (0 skipped); retracted 0 group-copies; removed 0 ripple-join membership(s).')
            ->assertExitCode(0);

        $this->assertNotNull($this->reachableGroupIds($msgid), 'a normal run rebuilds reachable_group_ids');
    }

    public function test_second_run_has_nothing_left_to_rebuild(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedMessage();
        $this->seedReachRow($msgid);

        $this->artisan('ripple:rebuild-reach')->assertExitCode(0);

        $this->artisan('ripple:rebuild-reach')
            ->expectsOutputToContain('Rebuilt 0/0 reach row(s) (0 skipped); retracted 0 group-copies; removed 0 ripple-join membership(s).')
            ->assertExitCode(0);
    }

    public function test_all_flag_resweeps_an_already_rebuilt_row(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedMessage();
        $this->seedReachRow($msgid);

        $this->artisan('ripple:rebuild-reach')->assertExitCode(0);
        $this->assertNotNull($this->reachableGroupIds($msgid));

        $this->artisan('ripple:rebuild-reach', ['--all' => true])
            ->expectsOutputToContain('Rebuilt 1/1 reach row(s)')
            ->assertExitCode(0);
    }

    public function test_msgid_option_restricts_the_run_to_a_single_message(): void
    {
        $this->fakeRouting();
        $msgidA = $this->seedMessage(51.5, -0.1);
        $msgidB = $this->seedMessage(51.55, -0.15);
        $this->seedReachRow($msgidA);
        $this->seedReachRow($msgidB);

        $this->artisan('ripple:rebuild-reach', ['--msgid' => $msgidA])
            ->expectsOutputToContain('Rebuilt 1/1 reach row(s)')
            ->assertExitCode(0);

        $this->assertNotNull($this->reachableGroupIds($msgidA), 'the targeted message is rebuilt');
        $this->assertNull($this->reachableGroupIds($msgidB), 'the other message is left untouched');
    }

    public function test_limit_option_caps_the_number_of_rows_processed(): void
    {
        $this->fakeRouting();
        $msgids = [];
        for ($i = 0; $i < 3; $i++) {
            $msgids[] = $this->seedMessage(51.5 + $i * 0.01, -0.1 - $i * 0.01);
        }
        foreach ($msgids as $msgid) {
            $this->seedReachRow($msgid);
        }

        $this->artisan('ripple:rebuild-reach', ['--limit' => 2])
            ->expectsOutputToContain('Rebuilt 2/2 reach row(s)')
            ->assertExitCode(0);

        $rebuilt = 0;
        foreach ($msgids as $msgid) {
            if ($this->reachableGroupIds($msgid) !== null) {
                $rebuilt++;
            }
        }
        $this->assertSame(2, $rebuilt, 'exactly --limit rows are rebuilt, the rest left for a later run');
    }
}
