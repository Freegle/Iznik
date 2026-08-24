<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GcReachGeometryCommandTest extends TestCase
{
    private const WKT1 = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const CONFIG_KEY_PASS = 'ripple_gc_reach_geometry_pass';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        DB::table('config')->where('key', self::CONFIG_KEY_PASS)->delete();
    }

    /** Insert (or reuse) a geom row for $wkt, backdate its createdat, return its hash. */
    private function seedGeom(string $wkt, \DateTimeInterface $createdAt): string
    {
        GeomShareService::upsertFromWkt($wkt);
        $hash = (string) DB::selectOne(
            'SELECT UNHEX(MD5(ST_AsBinary(ST_GeomFromText(?, 3857)))) AS h', [$wkt]
        )->h;
        DB::table('rippling_reach_geom')->where('hash', $hash)->update(['createdat' => $createdAt]);

        return $hash;
    }

    /** Point a fresh reach row's polygon_hash at $hash, so it is no longer unreferenced. */
    private function referenceHash(string $hash): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, polygon_hash, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             SELECT ?, 51.5, -0.1, geom, hash, ST_Envelope(geom), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL,
                    'expanding', NOW(), NOW()
               FROM rippling_reach_geom WHERE hash = ?",
            [$msgid, $hash]
        );

        return $msgid;
    }

    private function setAgreedPass(string $hash, \DateTimeInterface $at): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => self::CONFIG_KEY_PASS],
            ['value' => json_encode(['at' => $at->format(DATE_ATOM), 'candidates' => [strtolower(bin2hex($hash))]])]
        );
    }

    public function test_first_pass_only_records_an_old_orphan_and_deletes_nothing(): void
    {
        $hash = $this->seedGeom(self::WKT1, now()->subHours(48));

        $this->artisan('ripple:gc-reach-geometry', ['--grace-hours' => 24])
            ->expectsOutputToContain('1 unreferenced candidate')
            ->assertExitCode(0);

        $this->assertNotNull(
            DB::table('rippling_reach_geom')->where('hash', $hash)->first(),
            'the first pass only records candidates - nothing is deleted yet'
        );
    }

    public function test_two_passes_agreeing_after_the_grace_deletes_the_orphan(): void
    {
        $hash = $this->seedGeom(self::WKT1, now()->subHours(48));
        $this->setAgreedPass($hash, now()->subHours(25)); // older than the 24h grace

        $this->artisan('ripple:gc-reach-geometry', ['--grace-hours' => 24])
            ->expectsOutputToContain('Deleted 1 geometry')
            ->assertExitCode(0);

        $this->assertNull(DB::table('rippling_reach_geom')->where('hash', $hash)->first());
    }

    public function test_a_referenced_geometry_is_never_a_candidate(): void
    {
        $hash = $this->seedGeom(self::WKT1, now()->subHours(48));
        $this->referenceHash($hash);

        $this->artisan('ripple:gc-reach-geometry', ['--grace-hours' => 24])
            ->expectsOutputToContain('0 unreferenced candidate')
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('rippling_reach_geom')->where('hash', $hash)->first());
    }

    public function test_an_orphan_younger_than_grace_is_not_a_candidate(): void
    {
        $hash = $this->seedGeom(self::WKT1, now()); // freshly created

        $this->artisan('ripple:gc-reach-geometry', ['--grace-hours' => 24])
            ->expectsOutputToContain('0 unreferenced candidate')
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('rippling_reach_geom')->where('hash', $hash)->first());
    }

    public function test_dry_run_deletes_nothing_but_reports_what_it_would_do(): void
    {
        $hash = $this->seedGeom(self::WKT1, now()->subHours(48));
        $this->setAgreedPass($hash, now()->subHours(25));

        $this->artisan('ripple:gc-reach-geometry', ['--grace-hours' => 24, '--dry-run' => true])
            ->expectsOutputToContain('Would delete 1 geometry')
            ->assertExitCode(0);

        $this->assertNotNull(
            DB::table('rippling_reach_geom')->where('hash', $hash)->first(),
            'dry-run must not delete'
        );
    }

    /**
     * The window the two-pass design exists for: a hash looked orphaned in a
     * prior pass, but something started referencing it before the second pass
     * ran (the clip detach-then-repoint window, or a fresh backfill). It must
     * survive - the current pass's own anti-join no longer lists it as a
     * candidate, so the agreement in the stored pass cannot delete it alone.
     */
    public function test_a_hash_referenced_again_before_the_second_pass_survives(): void
    {
        $hash = $this->seedGeom(self::WKT1, now()->subHours(48));
        $this->setAgreedPass($hash, now()->subHours(25));
        $this->referenceHash($hash);

        // referenceHash() inserts the new reference directly - it does not go through
        // GeomShareService::upsertFromRow/upsertFromWkt, so it cannot itself refresh
        // createdat (which those DO now, on every ODKU hit, so the GC age clock means
        // "last touched" rather than "first created"). Re-assert the age explicitly so
        // this test proves the CURRENT-PASS anti-join catches the reference, not a
        // createdat side effect this helper happens not to trigger.
        DB::table('rippling_reach_geom')->where('hash', $hash)->update(['createdat' => now()->subHours(48)]);

        $this->artisan('ripple:gc-reach-geometry', ['--grace-hours' => 24])
            ->expectsOutputToContain('Deleted 0 geometr')
            ->assertExitCode(0);

        $this->assertNotNull(
            DB::table('rippling_reach_geom')->where('hash', $hash)->first(),
            'a hash referenced again must survive even though a prior pass thought it was orphaned'
        );
    }
}
