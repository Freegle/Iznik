<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\Comparison;
use App\Database\Expressions\DateAdd;
use App\Database\Expressions\DateSub;
use App\Database\Expressions\InsertedValue;
use App\Database\Expressions\Now;
use App\Database\Expressions\Rand;
use App\Database\JsonTable;
use App\Database\ReplaceInto;
use App\Database\Sql;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Both renderings of the date interval, random, null-safe comparison and upsert value expressions, and the
 * statement helpers (REPLACE INTO, JSON_TABLE source, interval literal). The MySQL text is pinned on either
 * engine; where the suite runs on PostgreSQL the PostgreSQL text is run too.
 */
class DialectBuildingBlocksTest extends TestCase
{
    private Grammar $pg;

    private Grammar $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pg = new PostgresGrammar(DB::connection());
        $this->mysql = new MySqlGrammar(DB::connection());
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function test_date_sub_and_add_render_on_both_engines(): void
    {
        $sub = new DateSub(new Now(), 3, 'DAY');
        $add = new DateAdd('created', 2, 'hour');

        $this->assertSame('DATE_SUB(NOW(), INTERVAL 3 DAY)', $sub->getValue($this->mysql));
        $this->assertSame("(NOW() - (CAST(3 AS numeric) * INTERVAL '1 day'))", $sub->getValue($this->pg));
        $this->assertSame('DATE_ADD(`created`, INTERVAL 2 HOUR)', $add->getValue($this->mysql));
        $this->assertSame("(\"created\" + (CAST(2 AS numeric) * INTERVAL '1 hour'))", $add->getValue($this->pg));
    }

    public function test_date_arithmetic_refuses_a_unit_it_does_not_know(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DateSub(new Now(), 1, 'FORTNIGHT');
    }

    public function test_date_sub_moves_a_time_back_on_postgresql(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        $row = DB::selectOne(
            'SELECT '.(new DateSub(new Now(), DB::raw('?'), 'DAY'))->getValue($this->pg).' < NOW() - INTERVAL \'59 hours\' AS older',
            [3]
        );

        $this->assertTrue((bool) $row->older);
    }

    public function test_rand_renders_per_engine(): void
    {
        $this->assertSame('RAND()', (new Rand())->getValue($this->mysql));
        $this->assertSame('random()', (new Rand())->getValue($this->pg));
    }

    public function test_null_safe_comparison_renders_per_engine(): void
    {
        $e = new Comparison('a', '<=>', 'b');

        $this->assertSame('`a` <=> `b`', $e->getValue($this->mysql));
        $this->assertSame('"a" IS NOT DISTINCT FROM "b"', $e->getValue($this->pg));
    }

    public function test_inserted_value_renders_per_engine(): void
    {
        $this->assertSame('VALUES(`count`)', (new InsertedValue('count'))->getValue($this->mysql));
        $this->assertSame('excluded."count"', (new InsertedValue('count'))->getValue($this->pg));
    }

    public function test_interval_literal_renders_for_the_connection(): void
    {
        $this->assertSame($this->onPostgres() ? "INTERVAL '2 second'" : 'INTERVAL 2 SECOND', Sql::interval(2, 'SECOND'));
    }

    public function test_json_table_source_is_typed_for_the_connection(): void
    {
        $text = JsonTable::from(['id' => ['BIGINT', '$.id'], 'lat' => ['DOUBLE', '$.lat']], 'jt');

        if ($this->onPostgres()) {
            $this->assertSame(
                "JSON_TABLE(CAST(? AS jsonb), '$[*]' COLUMNS (id BIGINT PATH '$.id', lat double precision PATH '$.lat')) AS jt",
                $text
            );
        } else {
            $this->assertSame("JSON_TABLE(?, '$[*]' COLUMNS (id BIGINT PATH '$.id', lat DOUBLE PATH '$.lat')) AS jt", $text);
        }
    }

    public function test_json_table_gives_one_row_per_array_element(): void
    {
        $rows = DB::query()
            ->fromRaw(JsonTable::from(['id' => ['BIGINT', '$.id'], 'lat' => ['DOUBLE', '$.lat']], 'jt'), [json_encode([
                ['id' => 7, 'lat' => 1.5],
                ['id' => 9, 'lat' => 2.5],
            ])])
            ->orderBy('jt.id')
            ->get(['jt.id', 'jt.lat']);

        $this->assertSame([7, 9], $rows->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1.5, 2.5], $rows->pluck('lat')->map(fn ($v) => (float) $v)->all());
    }

    public function test_replace_into_replaces_the_whole_row(): void
    {
        $gid = $this->createTestGroup()->id;
        $date = '2020-01-02';
        DB::table('stats')->where('date', $date)->delete();
        DB::table('stats')->insert(['date' => $date, 'end' => '2020-01-09', 'groupid' => $gid, 'type' => 'Weight', 'count' => 5]);

        ReplaceInto::rows('stats', [
            ['date' => $date, 'groupid' => $gid, 'type' => 'Weight', 'count' => 8],
        ], ['date', 'groupid', 'type']);

        $rows = DB::table('stats')->where('date', $date)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(8, (int) $rows[0]->count);
        // The column the statement did not name went back to its default, not to the old value.
        $this->assertStringNotContainsString('2020-01-09', (string) $rows[0]->end);

        DB::table('stats')->where('date', $date)->delete();
    }
}
