<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\Comparison;
use App\Database\Expressions\MbrContains;
use App\Database\Expressions\MbrIntersects;
use App\Database\Expressions\MbrWithin;
use App\Database\Expressions\Point;
use App\Database\Expressions\StAsText;
use App\Database\Expressions\StDistanceSphere;
use App\Database\Expressions\StGeomFromText;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\Value;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The spatial expressions that spell a thing differently on PostgreSQL/PostGIS. Each test pins the MySQL text
 * (unchanged) and the PostgreSQL text, and, when the suite runs on PostgreSQL, runs the PostgreSQL text.
 */
class ExpressionsPostgresSpatialTest extends TestCase
{
    private Grammar $pg;

    private Grammar $mysql;

    /** The grammar of the engine the suite is running on. What it renders is what gets run. */
    private Grammar $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pg = new PostgresGrammar(DB::connection());
        $this->mysql = new MySqlGrammar(DB::connection());
        $this->live = DB::connection()->getQueryGrammar();
    }

    /** Runs a scalar expression, rendered for the engine the suite is running on, and gives its value. */
    private function evaluate(string $sql): mixed
    {
        return DB::selectOne("SELECT {$sql} AS result")->result;
    }

    public function test_point_renders_a_postgis_point_on_postgresql(): void
    {
        $point = new Point(Value::of(1.5), Value::of(2.5));

        $this->assertSame('POINT(1.5, 2.5)', $point->getValue($this->mysql));
        $this->assertSame('ST_MakePoint(1.5, 2.5)', $point->getValue($this->pg));
    }

    public function test_stsrid_setter_renders_st_setsrid_on_postgresql_and_the_getter_is_unchanged(): void
    {
        $setter = new StSrid(new Point('lng', 'lat'), 4326);
        $getter = new StSrid('geometry');

        $this->assertSame('ST_SRID(POINT(`lng`, `lat`), 4326)', $setter->getValue($this->mysql));
        $this->assertSame('ST_SetSRID(ST_MakePoint("lng", "lat"), 4326)', $setter->getValue($this->pg));
        $this->assertSame('ST_SRID(`geometry`)', $getter->getValue($this->mysql));
        $this->assertSame('ST_SRID("geometry")', $getter->getValue($this->pg));
    }

    public function test_the_point_and_srid_pair_builds_a_labelled_point_on_postgresql(): void
    {
        // With the label the application uses on this engine (3857 on MySQL, 4326 on PostgreSQL) the point
        // reads back as longitude then latitude on both. MySQL reads a point labelled 4326 the other way
        // round, which is why it is not the label used there.
        $srid = (int) config('freegle.srid');

        $sql = (new StAsText(new StSrid(new Point(Value::of(1), Value::of(2)), $srid)))->getValue($this->live);
        $this->assertSame('POINT(1 2)', $this->evaluate($sql));

        $sql = (new StSrid(new StSrid(new Point(Value::of(1), Value::of(2)), $srid)))->getValue($this->live);
        $this->assertSame($srid, $this->evaluate($sql));
    }

    public function test_stdistancesphere_renders_st_distancesphere_on_postgresql(): void
    {
        $distance = new StDistanceSphere('a', 'b');

        $this->assertSame('ST_Distance_Sphere(`a`, `b`)', $distance->getValue($this->mysql));
        $this->assertSame('ST_DistanceSphere("a", "b")', $distance->getValue($this->pg));
    }

    public function test_stdistancesphere_between_two_built_points_is_in_metres_on_postgresql(): void
    {
        $a = new StSrid(new Point(Value::of(0), Value::of(0)), 4326);
        $b = new StSrid(new Point(Value::of(0), Value::of(1)), 4326);

        $this->assertEqualsWithDelta(111195.0, $this->evaluate((new StDistanceSphere($a, $b))->getValue($this->live)), 100.0);
    }

    public function test_stgeometrytype_renders_geometrytype_on_postgresql_so_the_answer_matches_mysql(): void
    {
        $type = new StGeometryType('polyindex');

        $this->assertSame('ST_GeometryType(`polyindex`)', $type->getValue($this->mysql));
        $this->assertSame('GeometryType("polyindex")', $type->getValue($this->pg));

        $point = new StGeomFromText(Value::of('POINT(5 5)'), 4326);
        $this->assertSame('POINT', $this->evaluate((new StGeometryType($point))->getValue($this->live)));
    }

    public function test_the_bounding_box_predicates_render_operators_on_postgresql(): void
    {
        $this->assertSame('MBRContains(`a`, `b`)', (new MbrContains('a', 'b'))->getValue($this->mysql));
        $this->assertSame('MBRWithin(`a`, `b`)', (new MbrWithin('a', 'b'))->getValue($this->mysql));
        $this->assertSame('MBRIntersects(`a`, `b`)', (new MbrIntersects('a', 'b'))->getValue($this->mysql));
        $this->assertSame('("a" ~ "b")', (new MbrContains('a', 'b'))->getValue($this->pg));
        $this->assertSame('("a" @ "b")', (new MbrWithin('a', 'b'))->getValue($this->pg));
        $this->assertSame('("a" && "b")', (new MbrIntersects('a', 'b'))->getValue($this->pg));
    }

    public function test_the_bounding_box_predicates_answer_like_mysql_on_postgresql(): void
    {
        $outer = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 4326);
        $inner = new StGeomFromText(Value::of('POLYGON((2 2, 2 4, 4 4, 4 2, 2 2))'), 4326);
        $far = new StGeomFromText(Value::of('POLYGON((20 20, 20 30, 30 30, 30 20, 20 20))'), 4326);

        $this->assertTrue((bool) $this->evaluate((new MbrContains($outer, $inner))->getValue($this->live)));
        $this->assertFalse((bool) $this->evaluate((new MbrContains($inner, $outer))->getValue($this->live)));
        $this->assertTrue((bool) $this->evaluate((new MbrWithin($inner, $outer))->getValue($this->live)));
        $this->assertTrue((bool) $this->evaluate((new MbrIntersects($outer, $inner))->getValue($this->live)));
        $this->assertFalse((bool) $this->evaluate((new MbrIntersects($outer, $far))->getValue($this->live)));
    }

    public function test_the_null_safe_equality_renders_is_not_distinct_from_on_postgresql(): void
    {
        $same = new Comparison('a', '<=>', 'b');

        $this->assertSame('`a` <=> `b`', $same->getValue($this->mysql));
        $this->assertSame('"a" IS NOT DISTINCT FROM "b"', $same->getValue($this->pg));
    }
}
