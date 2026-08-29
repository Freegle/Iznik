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


    public function testStoresUnionThresholdAndFingerprintInline(): void
    {
        // The grid-removal endgame fields ride along with the labels fetch:
        // the road-native union threshold, the union regions (merged into the
        // leaves so union-admitted members discover the post) and the build
        // fingerprint on every leaf row.
        $msgid = $this->seedReach();
        Http::fake([
            '*/v1/reach-labels*' => Http::response([
                'labels' => base64_encode(random_bytes(64)),
                'leaves' => [7, 42],
                'union_leaves' => [42, 88],
                'origin_union_secs' => 512.5,
                'fp' => '12345678901234567890',
                't' => 2700,
            ]),
        ]);

        $this->assertTrue(app(ReachService::class)->storeReachLabels($msgid, 51.5, -0.1, 45.0));

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertEqualsWithDelta(512.5, (float) $row->origin_union_secs, 0.001);
        $leaves = DB::table('rippling_reach_leaves')->where('msgid', $msgid)->orderBy('leaf')->get();
        $this->assertSame([7, 42, 88], $leaves->pluck('leaf')->map(fn ($l) => (int) $l)->all());
        foreach ($leaves as $leaf) {
            $this->assertSame('12345678901234567890', (string) $leaf->fp);
        }
        // The request asked with the msgid, so the server could resolve the
        // origin group.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'msgid=' . $msgid));
    }

    public function testStoreUnionSecsBackfillsFromStoredBlob(): void
    {
        // The backfill face: labels already stored, one reach-union call
        // fills the threshold, stamps the existing leaves with the build
        // fingerprint and merges the union regions.
        $msgid = $this->seedReach();
        $blob = random_bytes(64);
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => $blob]);
        DB::table('rippling_reach_leaves')->insert([
            ['msgid' => $msgid, 'leaf' => 7],
        ]);
        Http::fake([
            '*/v1/reach-union*' => Http::response([
                'origin_union_secs' => -1,
                'union_leaves' => [88],
                'fp' => '999',
            ]),
        ]);

        $this->assertTrue(app(ReachService::class)->storeUnionSecs($msgid));

        $this->assertEqualsWithDelta(-1.0, (float) DB::table('rippling_reach')->where('msgid', $msgid)->value('origin_union_secs'), 0.001);
        $leaves = DB::table('rippling_reach_leaves')->where('msgid', $msgid)->orderBy('leaf')->get();
        $this->assertSame([7, 88], $leaves->pluck('leaf')->map(fn ($l) => (int) $l)->all());
        foreach ($leaves as $leaf) {
            $this->assertSame('999', (string) $leaf->fp, 'existing rows are stamped, new rows written stamped');
        }
        // The blob went up base64d for the routing server to decode.
        Http::assertSent(fn ($req) => ($req['labels'] ?? '') === base64_encode($blob));
    }

    public function testStoreUnionSecsStaleBuildIsQuiet(): void
    {
        // 422 = the blob belongs to a partition build the routing server no
        // longer holds: the row keeps NULL (transitional behaviour) until the
        // label backfill refreshes it.
        $msgid = $this->seedReach();
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => 'blob']);
        Http::fake(['*/v1/reach-union*' => Http::response(null, 422)]);

        $this->assertFalse(app(ReachService::class)->storeUnionSecs($msgid));
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('origin_union_secs'));
    }
}
