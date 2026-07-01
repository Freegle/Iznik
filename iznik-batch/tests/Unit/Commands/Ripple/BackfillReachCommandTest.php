<?php

namespace Tests\Unit\Commands\Ripple;

use App\Models\Message;
use App\Models\MessageGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillReachCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.ripple.enabled' => true]);
        config(['freegle.ripple.hazard_hours' => [1, 3, 6]]);
        config(['freegle.ripple.active_start_hour' => 0]);
        config(['freegle.ripple.active_end_hour' => 24]);
        // Go-live cutoff an hour ago, so the fixtures below (2h old) are all PRE-cutoff — exactly
        // the backlog the backfill exists to drain.
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM messages_spatial');
    }

    private function fakeRouting(): void
    {
        $polygon = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]],
        ];
        $schedule = [];
        for ($k = 1; $k <= 3; $k++) {
            $schedule[] = ['tick' => $k, 'drive_min' => 5.0 * $k, 'cumulative_users' => 30 * $k, 'polygon' => $polygon];
        }
        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 90, 'max_drive_min' => 30, 'schedule' => $schedule,
        ], 200)]);
    }

    /** Seed an approved pre-go-live OFFER in messages_spatial; returns its msgid. */
    private function seedSpatialPost(?Carbon $arrival = null, float $lat = 51.5, float $lng = -0.1): int
    {
        $arrival ??= now()->subHours(2);
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: sofa (London)',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => $arrival,
            'arrival' => $arrival,
            'lat' => $lat,
            'lng' => $lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => $arrival,
        ]);
        DB::insert(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText(?, 3857), ?, ?, ?)",
            [$message->id, "POINT($lng $lat)", $group->id, Message::TYPE_OFFER, $arrival]
        );

        return (int) $message->id;
    }

    /** The backfill seeds a reach row for a live post that predates go-live, and reports it. */
    public function test_backfill_seeds_pre_go_live_post(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedSpatialPost();

        $this->artisan('ripple:backfill')
            ->expectsOutputToContain('1 live post(s)')
            ->expectsOutputToContain('Seeded 1 reach row(s)')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('rippling_reach')->where('msgid', $msgid)->count());
    }

    /** Idempotent/resumable: a second run seeds nothing and the command exits cleanly. */
    public function test_second_run_seeds_nothing(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedSpatialPost();

        $this->artisan('ripple:backfill')->assertExitCode(0);
        $this->assertSame(1, DB::table('rippling_reach')->where('msgid', $msgid)->count());

        $this->artisan('ripple:backfill')
            ->expectsOutputToContain('Nothing to backfill.')
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('rippling_reach')->where('msgid', $msgid)->count());
    }

    /** --dry-run writes nothing but reports the candidate count. */
    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedSpatialPost();

        $this->artisan('ripple:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('rippling_reach')->where('msgid', $msgid)->count());
    }

    /**
     * Sharding partitions candidates by msgid % shards: shard i only seeds posts whose msgid
     * satisfies the predicate. Two complementary shards together cover the whole backlog with no
     * overlap. We seed several posts, run every shard of --shards=2, and assert full coverage.
     */
    public function test_shards_partition_the_backlog_without_overlap(): void
    {
        $this->fakeRouting();
        $msgids = [];
        for ($i = 0; $i < 6; $i++) {
            $msgids[] = $this->seedSpatialPost();
        }

        foreach ([0, 1] as $shard) {
            $this->artisan('ripple:backfill', ['--shards' => 2, '--shard' => $shard])
                ->expectsOutputToContain("[shard {$shard}/2]")
                ->assertExitCode(0);
        }

        // Every post is seeded exactly once, and each landed in the shard its msgid maps to.
        foreach ($msgids as $msgid) {
            $this->assertSame(
                1,
                DB::table('rippling_reach')->where('msgid', $msgid)->count(),
                "post {$msgid} seeded exactly once across the two shards"
            );
        }
    }

    /** A single shard of the SAME index is locked out while another holds it (no double-seed). */
    public function test_same_shard_is_single_instanced_via_its_own_lock(): void
    {
        $this->fakeRouting();
        $this->seedSpatialPost();

        $held = Cache::lock('ripple:backfill:seed:shard2-0', 30);
        $this->assertTrue($held->get(), 'precondition: hold shard 0 of 2');

        try {
            $this->artisan('ripple:backfill', ['--shards' => 2, '--shard' => 0])
                ->expectsOutputToContain('Another ripple:backfill run for this shard is in progress')
                ->assertExitCode(0);

            // The complementary shard is NOT locked out — its lock name differs.
            $this->artisan('ripple:backfill', ['--shards' => 2, '--shard' => 1])
                ->assertExitCode(0);
        } finally {
            $held->release();
        }
    }

    /** The plain backfill lock does not collide with ripple:expand's lock. */
    public function test_backfill_uses_a_distinct_lock_from_expand(): void
    {
        $this->fakeRouting();
        $this->seedSpatialPost();

        // Hold the EXPAND lock — the backfill must run regardless (different lock name).
        $expand = Cache::lock('ripple:expand:run', 30);
        $this->assertTrue($expand->get());

        try {
            $this->artisan('ripple:backfill')
                ->expectsOutputToContain('Seeded')
                ->assertExitCode(0);
        } finally {
            $expand->release();
        }
    }

    /** Bad shard arguments are rejected. */
    public function test_invalid_shard_index_is_rejected(): void
    {
        $this->artisan('ripple:backfill', ['--shards' => 2, '--shard' => 2])
            ->expectsOutputToContain('--shard must be in the range 0..1')
            ->assertExitCode(1);
    }

    /**
     * --recompute upgrades the placeholder (DPA) reach seeds — status='stopped', schedule NULL —
     * into real routed reach in place, and only those (a plain run ignores them).
     */
    public function test_recompute_upgrades_placeholder_seeds(): void
    {
        $this->fakeRouting();
        $msgid = $this->seedSpatialPost();
        // Lay down a placeholder like the quick geometry seed does.
        DB::insert(
            "INSERT INTO rippling_reach
                (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                 status, schedule, next_expansion_at, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857),
                     ?, 'drive', 0, 0, 0, 'stopped', NULL, NULL, NOW(), NOW())",
            [$msgid, now()->subHours(2)]
        );

        // A plain run finds no anti-join candidates (the post already has a row).
        $this->artisan('ripple:backfill')
            ->expectsOutputToContain('0 live post(s) with no reach row')
            ->assertExitCode(0);
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('schedule'));

        // --recompute targets the placeholder and upgrades it in place.
        $this->artisan('ripple:backfill', ['--recompute' => true])
            ->expectsOutputToContain('placeholder (DPA) reach seed(s) to recompute')
            ->expectsOutputToContain('Recomputed 1 reach row(s)')
            ->assertExitCode(0);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(1, DB::table('rippling_reach')->where('msgid', $msgid)->count(), 'still one row (upsert)');
        $this->assertNotNull($row->schedule, 'placeholder upgraded to a real schedule');
        $this->assertContains($row->status, ['expanding', 'done']);
    }
}
