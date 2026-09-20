<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\CellSetService;
use App\Services\Ripple\ReachBoundsService;
use App\Services\Ripple\ReachQueryService;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesRingIndex;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

/**
 * What every reach read answers from the STORED LABEL - the reach record
 * (labels-truth). No verdict, for any reason, means not in reach: there is
 * no grid fallback anywhere. The two bounds tests at the end cover the one
 * grid-era piece still alive - the sandwich-bounds writer support for rows
 * the backfill has not labelled yet.
 */
class PostDropEraTest extends TestCase
{
    use FakesRingIndex;
    use SeedsReachCells;

    /** A box covering lng [-0.2, 0.0], lat [51.4, 51.6]. */
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    /** A smaller box wholly inside POLY, for the max-reach pair. */
    private const INNER_POLY = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private CellSetService $cells;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
        $this->fakeRingIndex();
        $this->cells = new CellSetService();
    }

    /**
     * A reach row with BOTH forms describing the same shape. The cells come
     * from the real rasteriser, so these are the bytes production would hold.
     */
    private function seedReach(bool $withMax = false): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lamp (London)',
            'textbody' => 'A lamp.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $cells = $this->reachCellsFor(self::POLY);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?,
                     ST_Buffer(ST_Simplify(ST_GeomFromText(?, 3857), 0.002), 0.002),
                     NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $cells, self::POLY]
        );

        if ($withMax) {
            $maxCells = $this->reachCellsFor(self::POLY);
            $nowCells = $this->reachCellsFor(self::INNER_POLY);
            DB::statement(
                'UPDATE rippling_reach SET polygon_cells = ?, max_polygon_cells = ? WHERE msgid = ?',
                [$nowCells, $maxCells, $message->id]
            );
        }

        return (int) $message->id;
    }

    // ---- the reply gate ----

    /** Http::fake merges first-stub-wins, so ONE callback reads this. */
    private string $verdict = 'nolabels';

    private bool $verdictFakeInstalled = false;

    private function fakeVerdict(string $verdict): void
    {
        $this->verdict = $verdict;
        if ($this->verdictFakeInstalled) {
            return;
        }
        $this->verdictFakeInstalled = true;
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (!str_contains($request->url(), 'reach-eval')) {
                return null;
            }
            $results = array_map(
                fn ($id) => ['msgid' => (int) $id, 'verdict' => $this->verdict],
                $request['msgids'] ?? []
            );

            return \Illuminate\Support\Facades\Http::response(['results' => $results]);
        });
    }

    public function test_reply_gate_admits_on_the_label_verdict_alone(): void
    {
        $msgid = $this->seedReach();
        $this->fakeVerdict('in');
        $this->assertTrue((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
    }

    public function test_reply_gate_refuses_on_the_label_verdict_alone(): void
    {
        $msgid = $this->seedReach();
        $this->fakeVerdict('out');
        $this->assertFalse((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
    }

    /**
     * Routing unreachable must HOLD the reply (fail closed), not admit it:
     * there is nothing to fall back to, and admitting blind would be the
     * unsafe direction. The release cron re-asks when routing returns.
     */
    public function test_reply_gate_fails_closed_when_routing_cannot_answer(): void
    {
        $msgid = $this->seedReach();
        \App\Services\Ripple\ReachService::resetLabelEvalBreaker();
        \Illuminate\Support\Facades\Http::fake(['*reach-eval*' => \Illuminate\Support\Facades\Http::response(null, 500)]);

        $this->assertFalse((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
        \App\Services\Ripple\ReachService::resetLabelEvalBreaker();
    }

    public function test_reply_gate_refuses_when_the_post_has_no_label(): void
    {
        $msgid = $this->seedReach();
        $this->fakeVerdict('nolabels');
        $this->assertFalse((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
    }

    // ---- the first-reply passthrough gate ----

    public function test_max_reach_gate_asks_the_label_at_its_full_budget(): void
    {
        $msgid = $this->seedReach(withMax: true);
        $svc = app(\App\Services\FirstReply\MaxReachService::class);

        $this->fakeVerdict('in');
        $this->assertTrue($svc->isWithinMaxReach($msgid, 51.42, -0.18));
        \Illuminate\Support\Facades\Http::assertSent(
            fn ($req) => str_contains($req->url(), 'reach-eval') && ($req['budget'] ?? '') === 'max'
        );

        $this->fakeVerdict('out');
        $this->assertFalse($svc->isWithinMaxReach($msgid, 52.0, 1.0));
    }

    // ---- how far outside the reach a held replier is ----

    public function test_miles_outside_reach_comes_from_the_label(): void
    {
        $msgid = $this->seedReach();
        $svc = new RippleReplyService(new ReachQueryService());

        $method = new \ReflectionMethod($svc, 'milesOutsideReach');
        $method->setAccessible(true);

        // The label admits: zero miles outside.
        $this->fakeVerdict('in');
        $this->assertSame(0.0, $method->invoke($svc, $msgid, 51.5, -0.1));

        // The label refuses: the label carries no miles, so the caller's
        // documented origin-distance measure takes over (null here).
        $this->fakeVerdict('out');
        $this->assertNull($method->invoke($svc, $msgid, 51.5, 0.5));
    }

    // ---- the sandwich bounds, re-derived from the grid ----

    public function test_bounds_are_derived_from_the_traced_grid(): void
    {
        $msgid = $this->seedReach();
        DB::statement('UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = NULL WHERE msgid = ?',
            ['POLYGON((-0.01 51.49, 0.0 51.49, 0.0 51.5, -0.01 51.5, -0.01 51.49))', $msgid]);

        (new ReachBoundsService())->syncFromPolygon($msgid);

        // Whatever it derived, the outer bound must CONTAIN the reach: that is
        // the superset guarantee every degraded read path depends on.
        $row = DB::selectOne(
            'SELECT ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS ok FROM rippling_reach WHERE msgid = ?',
            [self::POLY, $msgid]
        );
        $this->assertSame(1, (int) $row->ok, 'outer_bound must be a superset of the reach after re-derivation');
    }

    /**
     * With no cells there is nothing to derive from. The bounds must be left
     * alone (a stale outer is safe-loose) and the inner cleared (a stale
     * inner would cheap-accept people who are no longer covered).
     */
    public function test_bounds_derivation_clears_the_inner_when_it_cannot_measure(): void
    {
        $msgid = $this->seedReach();
        DB::statement('UPDATE rippling_reach SET polygon_cells = NULL, inner_bound = ST_GeomFromText(?, 3857) WHERE msgid = ?',
            [self::INNER_POLY, $msgid]);

        (new ReachBoundsService())->syncFromPolygon($msgid);

        $inner = DB::table('rippling_reach')->where('msgid', $msgid)->value('inner_bound');
        $this->assertNull($inner, 'an unmeasurable reach must lose its cheap-accept bound');
    }

}
