<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reach-engine label storage: ReachService::storeReachLabels fetches routing
 * /v1/reach-labels once at the post's maximum budget and stores the blob plus
 * the reached-region rows. Best-effort by contract: any failure leaves the row
 * untouched (readers fall back to cells) and never throws.
 */
class ReachLabelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
        DB::table('rippling_reach_leaves')->delete();
    }

    /** A real message row (rippling_reach.msgid is a foreign key) plus its reach row. */
    private function seedReach(): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $user->id,
            'subject' => 'OFFER: labels', 'textbody' => 'x', 'source' => 'Platform',
            'date' => now()->subDay(), 'arrival' => now()->subDay(), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1,
                     ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857),
                     NOW(), 'drive', 1, 3, 0, 45, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id]
        );

        return (int) $message->id;
    }

    public function testStoresLabelsAndLeaves(): void
    {
        $msgid = $this->seedReach();
        $blob = random_bytes(64);
        Http::fake([
            '*/v1/reach-labels*' => Http::response([
                'labels' => base64_encode($blob),
                'leaves' => [7, 42, 42, 99],
                't' => 2700,
            ]),
        ]);

        $ok = app(ReachService::class)->storeReachLabels($msgid, 51.5, -0.1, 45.0);

        $this->assertTrue($ok);
        $stored = DB::table('rippling_reach')->where('msgid', $msgid)->value('reach_labels');
        $this->assertSame($blob, $stored);
        $leaves = DB::table('rippling_reach_leaves')->where('msgid', $msgid)->orderBy('leaf')->pluck('leaf')->all();
        $this->assertSame([7, 42, 99], $leaves);
    }

    public function testReplacesLeavesOnRestore(): void
    {
        $msgid = $this->seedReach();
        DB::table('rippling_reach_leaves')->insert([
            ['msgid' => $msgid, 'leaf' => 1],
            ['msgid' => $msgid, 'leaf' => 2],
        ]);
        Http::fake([
            '*/v1/reach-labels*' => Http::response([
                'labels' => base64_encode('newblob'),
                'leaves' => [2, 3],
            ]),
        ]);

        $this->assertTrue(app(ReachService::class)->storeReachLabels($msgid, 51.5, -0.1, 45.0));
        $leaves = DB::table('rippling_reach_leaves')->where('msgid', $msgid)->orderBy('leaf')->pluck('leaf')->all();
        $this->assertSame([2, 3], $leaves);
    }

    public function testEngineUnconfiguredIsQuietNoOp(): void
    {
        $msgid = $this->seedReach();
        Http::fake(['*/v1/reach-labels*' => Http::response('reach engine not configured', 503)]);

        $this->assertFalse(app(ReachService::class)->storeReachLabels($msgid, 51.5, -0.1, 45.0));
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('reach_labels'));
        $this->assertSame(0, DB::table('rippling_reach_leaves')->where('msgid', $msgid)->count());
    }

    public function testMalformedResponseLeavesRowUntouched(): void
    {
        $msgid = $this->seedReach();
        Http::fake(['*/v1/reach-labels*' => Http::response(['labels' => '***not-base64***', 'leaves' => 'nope'])]);

        $this->assertFalse(app(ReachService::class)->storeReachLabels($msgid, 51.5, -0.1, 45.0));
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('reach_labels'));
    }

    public function testBackfillCommandFillsMissingRows(): void
    {
        $msgid = $this->seedReach();
        Http::fake([
            '*/v1/reach-labels*' => Http::response([
                'labels' => base64_encode('blob'),
                'leaves' => [5],
            ]),
        ]);

        $this->artisan('ripple:backfill-reach-labels', ['--sleep-ms' => 0])
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('reach_labels'));
        $this->assertSame([5], DB::table('rippling_reach_leaves')->where('msgid', $msgid)->pluck('leaf')->all());
    }

    public function testBackfillAllRefetchesRowsWithLabels(): void
    {
        $msgid = $this->seedReach();
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => 'stale-partition-blob']);
        DB::table('rippling_reach_leaves')->insert([['msgid' => $msgid, 'leaf' => 1]]);
        Http::fake([
            '*/v1/reach-labels*' => Http::response([
                'labels' => base64_encode('fresh'),
                'leaves' => [8, 9],
            ]),
        ]);

        // Default run skips rows that already have labels...
        $this->artisan('ripple:backfill-reach-labels', ['--sleep-ms' => 0])->assertExitCode(0);
        $this->assertSame('stale-partition-blob', DB::table('rippling_reach')->where('msgid', $msgid)->value('reach_labels'));

        // ...--all re-fetches them (the partition-rebuild path).
        $this->artisan('ripple:backfill-reach-labels', ['--sleep-ms' => 0, '--all' => true])->assertExitCode(0);
        $this->assertSame('fresh', DB::table('rippling_reach')->where('msgid', $msgid)->value('reach_labels'));
        $this->assertSame([8, 9], DB::table('rippling_reach_leaves')->where('msgid', $msgid)->orderBy('leaf')->pluck('leaf')->all());
    }
}
