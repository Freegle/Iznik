<?php

namespace Tests\Unit\Database;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->pg = new PostgresGrammar(DB::connection());
        $this->mysql = new MySqlGrammar(DB::connection());
    }

    private function evaluate(string $sql): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

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
        $sql = (new StAsText(new StSrid(new Point(Value::of(1), Value::of(2)), 4326)))->getValue($this->pg);
        $this->assertSame('POINT(1 2)', $this->evaluate($sql));

        $sql = (new StSrid(new StSrid(new Point(Value::of(1), Value::of(2)), 4326)))->getValue($this->pg);
        $this->assertSame(4326, $this->evaluate($sql));
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

        $this->assertEqualsWithDelta(111195.0, $this->evaluate((new StDistanceSphere($a, $b))->getValue($this->pg)), 100.0);
    }

    public function test_stgeometrytype_renders_geometrytype_on_postgresql_so_the_answer_matches_mysql(): void
    {
        $type = new StGeometryType('polyindex');

        $this->assertSame('ST_GeometryType(`polyindex`)', $type->getValue($this->mysql));
        $this->assertSame('GeometryType("polyindex")', $type->getValue($this->pg));

        $point = new StGeomFromText(Value::of('POINT(5 5)'), 4326);
        $this->assertSame('POINT', $this->evaluate((new StGeometryType($point))->getValue($this->pg)));
    }
}
