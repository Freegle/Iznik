<?php

namespace Tests\Unit\Commands\Ripple;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ripple:backfill-reach-bounds — one-off, paced, resumable backfill of the sandwich
 * bounds table for reach rows that predate the bounds writers
 * (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). Resumability comes from the
 * anti-join (only rows with no bounds row are candidates), so re-runs converge to
 * a no-op instead of re-deriving everything.
 */
class BackfillReachBoundsCommandTest extends TestCase
{
    private const WKT = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
    }

    private function seedReach(): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: backfill fixture (London)',
            'textbody' => 'A thing.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::WKT]
        );

        return (int) $message->id;
    }

    public function test_backfills_only_reaches_without_bounds_and_is_resumable(): void
    {
        $a = $this->seedReach();
        $b = $this->seedReach();
        $c = $this->seedReach();
        // $c already has bounds — must not be re-derived (its row is recognisable by the
        // sentinel envelope-only shape we give it here).
        DB::statement(
            "INSERT INTO rippling_reach_bounds (msgid, outer_bound, inner_bound)
             VALUES (?, ST_GeomFromText(?, 3857), NULL)",
            [$c, self::WKT]
        );

        $this->artisan('ripple:backfill-reach-bounds')
            ->assertExitCode(0);

        foreach ([$a, $b] as $msgid) {
            $check = DB::selectOne(
                'SELECT ST_Contains(b.outer_bound, rr.polygon) AS o,
                        (b.inner_bound IS NULL OR ST_Contains(rr.polygon, b.inner_bound)) AS i
                   FROM rippling_reach_bounds b
                   JOIN rippling_reach rr ON rr.msgid = b.msgid
                  WHERE b.msgid = ?',
                [$msgid]
            );
            $this->assertNotNull($check, "backfill wrote bounds for $msgid");
            $this->assertSame(1, (int) $check->o);
            $this->assertSame(1, (int) $check->i);
        }

        // The pre-existing row was left alone (still inner-NULL sentinel).
        $this->assertSame(
            1,
            (int) DB::selectOne(
                'SELECT inner_bound IS NULL AS n FROM rippling_reach_bounds WHERE msgid = ?',
                [$c]
            )->n,
            'rows that already have bounds are not touched'
        );

        // Second run: nothing left to do (anti-join resumability).
        $this->artisan('ripple:backfill-reach-bounds')
            ->expectsOutputToContain('Backfilled 0 bounds')
            ->assertExitCode(0);
    }

    public function test_backfill_degrades_bounds_of_already_completed_posts(): void
    {
        // The outcome hook (MessageSpatialService) degrades bounds only on the
        // successful 0→1 TRANSITION. Posts completed BEFORE the backfill never see a
        // transition, so the backfill itself must leave their bounds degraded — or the
        // completed-post pruning (~46% of candidates) is permanently lost for the
        // backlog it exists to cover.
        $open = $this->seedReach();
        $completed = $this->seedReach();
        $group = $this->createTestGroup();
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival, successful)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, 'Offer', NOW(), 0)",
            [$open, $group->id]
        );
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival, successful)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, 'Offer', NOW(), 1)",
            [$completed, $group->id]
        );

        $this->artisan('ripple:backfill-reach-bounds')->assertExitCode(0);

        $outerType = fn (int $msgid): ?string => DB::selectOne(
            'SELECT ST_GeometryType(outer_bound) AS t FROM rippling_reach_bounds WHERE msgid = ?',
            [$msgid]
        )->t ?? null;
        $this->assertSame('POLYGON', $outerType($open), 'open post gets real bounds');
        $this->assertSame('POINT', $outerType($completed), 'already-completed post ends degraded');
    }

    public function test_backfill_respects_limit(): void
    {
        $this->seedReach();
        $this->seedReach();

        $this->artisan('ripple:backfill-reach-bounds', ['--limit' => 1])
            ->expectsOutputToContain('Backfilled 1 bounds')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            (int) DB::table('rippling_reach_bounds')->count(),
            'only --limit rows are processed per run'
        );
    }
}
