<?php

namespace Tests\Unit\Database;

use App\Database\Sql;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlTest extends TestCase
{
    public function test_table_is_quoted_for_the_connections_dialect(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql' ? '"groups"' : '`groups`';

        $this->assertSame($expected, Sql::table('groups'));
    }

    public function test_drop_temporary_table_never_names_a_permanent_table(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql'
            ? 'DROP TABLE IF EXISTS pg_temp."pc"'
            : 'DROP TEMPORARY TABLE IF EXISTS pc';

        $this->assertSame($expected, Sql::dropTemporaryTable('pc'));
    }

    public function test_json_helpers_render_for_the_connections_dialect(): void
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';

        $this->assertSame(
            $pg ? "(CAST(settings AS jsonb) #> '{a,b}'::text[])" : "JSON_EXTRACT(settings, '$.a.b')",
            Sql::jsonExtract('settings', '$.a.b')
        );
        $this->assertSame(
            $pg ? "(COALESCE(rr.ids, '[]'::jsonb) @> to_jsonb(g.id))" : 'JSON_CONTAINS(COALESCE(rr.ids, JSON_ARRAY()), CAST(g.id AS JSON))',
            Sql::jsonArrayHas('rr.ids', 'g.id')
        );
        $this->assertSame(
            $pg
                ? "(COALESCE(CAST(settings AS jsonb) #>> '{newsletter}'::text[], '0') NOT IN ('0', 'false'))"
                : "COALESCE(JSON_EXTRACT(settings, '$.newsletter'), 0) != 0",
            Sql::jsonSettingEnabled('settings', '$.newsletter')
        );
    }

    public function test_force_index_is_a_mysql_hint_and_nothing_on_postgresql(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql' ? '' : ' FORCE INDEX (arrival)';

        $this->assertSame($expected, Sql::forceIndex('arrival'));
    }

    public function test_json_has_path_renders_for_the_connections_dialect(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql'
            ? 'jsonb_path_exists(CAST(cells AS jsonb), CAST(? AS jsonpath))'
            : "JSON_CONTAINS_PATH(cells, 'one', ?)";

        $this->assertSame($expected, Sql::jsonHasPath('cells', '?'));
    }
}
