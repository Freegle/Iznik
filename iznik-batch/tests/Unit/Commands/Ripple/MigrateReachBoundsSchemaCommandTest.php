<?php

namespace Tests\Unit\Commands\Ripple;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ripple:migrate-reach-bounds-schema — the pt-osc-style shadow copy for production
 * (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). In this test DB the migration has
 * already added the bounds columns, so the command's normal path is the no-op guard;
 * the copy/swap machinery is exercised by dropping the columns first (small table —
 * plain ALTER is fine here, unlike production).
 *
 * DDL implicitly commits, so DatabaseTransactions cannot roll this test back — every
 * fixture is cleaned up explicitly and the schema is restored to the migrated state.
 */
class MigrateReachBoundsSchemaCommandTest extends TestCase
{
    private const WKT = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private function seedReach(int $groupid, bool $completed): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: shadow fixture (London)',
            'textbody' => 'A thing.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        DB::statement(
            'INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), \'drive\', 1, 3, 90, 30, NULL, NULL, \'expanding\', NOW(), NOW())',
            [$message->id, self::WKT]
        );
        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival, successful)
             VALUES (?, ST_GeomFromText(\'POINT(-0.1 51.5)\', 3857), ?, \'Offer\', NOW(), ?)',
            [$message->id, $groupid, $completed ? 1 : 0]
        );

        return (int) $message->id;
    }

    public function test_shadow_copy_swaps_to_bounds_schema_with_sentinel_ladder(): void
    {
        // Start from the PRE-migration shape the command exists for.
        DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_outer');
        DB::statement('ALTER TABLE rippling_reach DROP COLUMN outer_bound, DROP COLUMN inner_bound');

        $group = $this->createTestGroup();
        $open = $this->seedReach($group->id, false);
        $completed = $this->seedReach($group->id, true);

        try {
            // Dry run first: reports, changes nothing.
            $this->artisan('ripple:migrate-reach-bounds-schema')
                ->expectsOutputToContain('DRY RUN')
                ->assertExitCode(0);
            $this->assertFalse(Schema::hasColumn('rippling_reach', 'outer_bound'), 'dry run changes nothing');

            $this->artisan('ripple:migrate-reach-bounds-schema', ['--execute' => true, '--sleep-ms' => 0])
                ->assertExitCode(0);

            // Post-swap: the live table has the full target schema.
            $this->assertTrue(Schema::hasColumn('rippling_reach', 'outer_bound'));
            $this->assertTrue(Schema::hasTable('rippling_reach_old'), 'displaced table kept for inspection');
            $idx = DB::select("SHOW INDEX FROM rippling_reach WHERE Key_name = 'rippling_reach_outer'");
            $this->assertNotEmpty($idx, 'the spatial index exists on the swapped table');

            // Sentinel ladder: open post got real verified bounds; completed post got the
            // POINT sentinel (pruned from the R-tree from day one).
            $openRow = DB::selectOne(
                'SELECT ST_GeometryType(outer_bound) AS t, ST_Contains(outer_bound, polygon) AS o,
                        (inner_bound IS NULL OR ST_Contains(polygon, inner_bound)) AS i
                   FROM rippling_reach WHERE msgid = ?',
                [$open]
            );
            $this->assertNotSame('POINT', $openRow->t, 'open post gets real bounds');
            $this->assertSame(1, (int) $openRow->o, 'outer contains the polygon');
            $this->assertSame(1, (int) $openRow->i, 'inner is NULL or inside the polygon');

            $this->assertSame(
                'POINT',
                DB::selectOne('SELECT ST_GeometryType(outer_bound) AS t FROM rippling_reach WHERE msgid = ?', [$completed])->t,
                'already-completed post is degraded to the POINT sentinel'
            );

            // Re-run: no-op guard.
            $this->artisan('ripple:migrate-reach-bounds-schema', ['--execute' => true])
                ->expectsOutputToContain('nothing to do')
                ->assertExitCode(0);
        } finally {
            // DDL committed everything: clean up fixtures and scaffolding explicitly.
            DB::statement('DROP TABLE IF EXISTS rippling_reach_old');
            DB::statement('DROP TABLE IF EXISTS rippling_reach_shadow');
            DB::delete('DELETE FROM rippling_reach WHERE msgid IN (?, ?)', [$open, $completed]);
            DB::delete('DELETE FROM messages_spatial WHERE msgid IN (?, ?)', [$open, $completed]);
            DB::delete('DELETE FROM messages WHERE id IN (?, ?)', [$open, $completed]);
            // If the swap never happened (assertion failure mid-test), restore the
            // migrated schema so later tests aren't poisoned.
            if (!Schema::hasColumn('rippling_reach', 'outer_bound')) {
                DB::statement('ALTER TABLE rippling_reach
                    ADD COLUMN outer_bound GEOMETRY SRID 3857 NULL,
                    ADD COLUMN inner_bound GEOMETRY SRID 3857 NULL');
                DB::statement('UPDATE rippling_reach SET outer_bound = ST_Envelope(polygon)');
                DB::statement('ALTER TABLE rippling_reach MODIFY outer_bound GEOMETRY NOT NULL SRID 3857');
                DB::statement('ALTER TABLE rippling_reach ADD SPATIAL INDEX rippling_reach_outer (outer_bound)');
            }
        }
    }
}
