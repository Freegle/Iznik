<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeomShareServiceTest extends TestCase
{
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const POLY2 = 'POLYGON((1.0 52.0, 1.2 52.0, 1.2 52.2, 1.0 52.2, 1.0 52.0))';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
    }

    private function hashOf(string $wkt): string
    {
        return (string) DB::selectOne(
            'SELECT UNHEX(MD5(ST_AsBinary(ST_GeomFromText(?, 3857)))) AS h',
            [$wkt]
        )->h;
    }

    public function test_ready_detects_the_real_migrated_schema(): void
    {
        $this->assertTrue(GeomShareService::ready(), 'the worktree test schema has the migration applied');
    }

    public function test_upsert_from_wkt_stores_the_hash_mysql_would_compute(): void
    {
        GeomShareService::upsertFromWkt(self::POLY);

        $row = DB::table('rippling_reach_geom')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->hashOf(self::POLY), (string) $row->hash);
    }

    public function test_upsert_from_wkt_is_idempotent(): void
    {
        GeomShareService::upsertFromWkt(self::POLY);
        GeomShareService::upsertFromWkt(self::POLY);
        GeomShareService::upsertFromWkt(self::POLY);

        $this->assertSame(1, DB::table('rippling_reach_geom')->count());
    }

    public function test_different_geometry_makes_a_different_row(): void
    {
        GeomShareService::upsertFromWkt(self::POLY);
        GeomShareService::upsertFromWkt(self::POLY2);

        $this->assertSame(2, DB::table('rippling_reach_geom')->count());
    }

    private function seedReachRow(string $polygonWkt): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(),
                     'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', ?, ?)",
            [$message->id, $polygonWkt, $polygonWkt, now()->subDay(), now()->subDay()]
        );

        return (int) $message->id;
    }

    public function test_upsert_from_row_then_rehash_from_row_points_the_hash_at_the_stored_blob(): void
    {
        $msgid = $this->seedReachRow(self::POLY);
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at');

        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame($this->hashOf(self::POLY), (string) $row->polygon_hash);
        $this->assertSame((string) $before, (string) $row->updated_at, 'updated_at is held still - it changed no geometry');

        $geom = DB::table('rippling_reach_geom')->where('hash', $row->polygon_hash)->first();
        $this->assertNotNull($geom);
        $stored = DB::selectOne('SELECT ST_AsBinary(polygon) AS b FROM rippling_reach WHERE msgid = ?', [$msgid])->b;
        $shared = DB::selectOne('SELECT ST_AsBinary(geom) AS b FROM rippling_reach_geom WHERE hash = ?', [$row->polygon_hash])->b;
        $this->assertSame($stored, $shared, 'the shared row holds byte-identical bytes to the blob it was hashed from');
    }

    public function test_join_sql_and_source_expr_return_the_coalesce_form_when_ready(): void
    {
        $join = GeomShareService::joinSql('rr', 'polygon', 'g');
        $this->assertStringContainsString('LEFT JOIN rippling_reach_geom g', $join);
        $this->assertStringContainsString('g.hash = rr.polygon_hash', $join);

        $expr = GeomShareService::sourceExpr('rr', 'polygon', 'g');
        $this->assertSame('COALESCE(g.geom, rr.polygon)', $expr);
    }

    public function test_forget_ready_re_detects_from_the_real_schema(): void
    {
        GeomShareService::forgetReady();
        $this->assertTrue(GeomShareService::ready(), 'the memoized flag is rebuilt from the actual migrated schema');
    }

    public function test_assert_column_rejects_a_non_shareable_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GeomShareService::upsertFromRow(1, 'outer_bound');
    }

    public function test_assert_column_rejects_via_join_sql_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GeomShareService::joinSql('rr', 'inner_bound', 'g');
    }

    public function test_drained_expr_matches_only_the_sentinel(): void
    {
        $sentinel = (int) DB::selectOne(
            "SELECT " . GeomShareService::drainedExpr('t', 'polygon') . " AS d
               FROM (SELECT ST_GeomFromText('" . GeomShareService::DRAIN_SENTINEL_WKT . "', 3857) AS polygon) t"
        )->d;
        $this->assertSame(1, $sentinel);

        $real = (int) DB::selectOne(
            "SELECT " . GeomShareService::drainedExpr('t', 'polygon') . " AS d
               FROM (SELECT ST_GeomFromText(?, 3857) AS polygon) t",
            [self::POLY]
        )->d;
        $this->assertSame(0, $real);
    }
}
