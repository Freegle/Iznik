<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\JsonLength;
use App\Database\Expressions\JsonNumber;
use App\Database\Expressions\Value;
use App\Database\JsonTextPostgresConnection;
use App\Database\JsonTextPostgresGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** JSON paths in the query builder, and JSON numbers in expressions, on a text column holding JSON. */
class JsonTextPostgresTest extends TestCase
{
    private function pgGrammar(): JsonTextPostgresGrammar
    {
        return new JsonTextPostgresGrammar(new JsonTextPostgresConnection(fn () => null));
    }

    public function test_json_path_casts_a_text_column_to_jsonb(): void
    {
        $sql = $this->pgGrammar()->wrap('users.settings->a->b');

        $this->assertSame('"users"."settings"::jsonb->\'a\'->>\'b\'', $sql);
    }

    public function test_json_number_renders_json_extract_on_mysql(): void
    {
        $e = new JsonNumber('settings', Value::of('$.a'));

        $this->assertSame("JSON_EXTRACT(`settings`, '\$.a')", $e->getValue(new MySqlGrammar(DB::connection())));
    }

    public function test_json_number_reads_numbers_and_booleans_on_postgresql(): void
    {
        $doc = Value::of('{"n": 7, "z": 0, "t": true, "f": false, "s": "x"}');
        $read = fn (string $path) => DB::selectOne(
            'SELECT '.(new JsonNumber($doc, Value::of($path)))->getValue(DB::connection()->getQueryGrammar()).' AS result'
        )->result;

        // Numbers and an absent member read the same on both engines.
        $this->assertEquals(7, $read('$.n'));
        $this->assertEquals(0, $read('$.z'));
        $this->assertNull($read('$.missing'));

        if (! $this->postgresOnly('true, false and text are read as 1, 0 and NULL through a PostgreSQL function; MySQL compares the JSON value itself')) {
            return;
        }

        $this->assertEquals(1, $read('$.t'));
        $this->assertEquals(0, $read('$.f'));
        $this->assertNull($read('$.s'));
    }

    public function test_query_builder_json_path_works_on_a_text_column(): void
    {
        if (! $this->postgresOnly('the text-column JSON path is the PostgreSQL grammar\'s work')) {
            return;
        }

        $this->assertInstanceOf(JsonTextPostgresGrammar::class, DB::connection()->getQueryGrammar());
        $this->assertTrue(
            (bool) DB::table(DB::raw("(SELECT '{\"a\": {\"b\": true}}'::text AS settings) t"))->where('settings->a->b', true)->exists()
        );
    }

    public function test_json_length_renders_json_length_on_mysql(): void
    {
        $e = new JsonLength('metadata', Value::of('$.ids'));

        $this->assertSame("JSON_LENGTH(`metadata`, '\$.ids')", $e->getValue(new MySqlGrammar(DB::connection())));
    }

    public function test_json_length_counts_array_elements_and_object_members(): void
    {

        $doc = Value::of('{"a": [1, 2, 3], "o": {"x": 1, "y": 2}, "s": "text", "e": []}');
        $length = fn (string $path) => DB::selectOne(
            'SELECT '.(new JsonLength($doc, Value::of($path)))->getValue(DB::connection()->getQueryGrammar()).' AS result'
        )->result;

        $this->assertEquals(3, $length('$.a'));
        $this->assertEquals(2, $length('$.o'));
        $this->assertEquals(1, $length('$.s'));
        $this->assertEquals(0, $length('$.e'));
        $this->assertNull($length('$.missing'));
    }

    /** A connection with no database behind it, told which columns are citext. */
    private function citextConnection(): JsonTextPostgresConnection
    {
        $connection = new JsonTextPostgresConnection(fn () => null);
        $connection->getQueryGrammar()->useCitextColumns([
            'users_emails' => ['email'],
            'locations' => ['name'],
        ]);

        return $connection;
    }

    /**
     * A LIKE on a citext column lowers both sides, so it matches without regard to case as MySQL does, and a
     * prefix pattern can use an index on (lower(col::text)) text_pattern_ops.
     */
    public function test_like_on_a_citext_column_lowers_both_sides(): void
    {
        $c = $this->citextConnection();

        $this->assertSame(
            'select * from "users_emails" where lower("email"::text) LIKE lower(?) order by "id" asc',
            $c->table('users_emails')->where('email', 'LIKE', 'x-g%')->orderBy('id')->toSql()
        );
        $this->assertSame(
            'select * from "locations" where lower("locations"."name"::text) NOT LIKE lower(?)',
            $c->table('locations')->where('locations.name', 'NOT LIKE', 'BF%')->toSql()
        );
    }

    public function test_like_on_a_citext_column_is_found_through_aliases_and_joins(): void
    {
        $c = $this->citextConnection();

        $this->assertSame(
            'select * from "users_emails" as "ue" where lower("ue"."email"::text) like lower(?)',
            $c->table('users_emails as ue')->where('ue.email', 'like', 'a%')->toSql()
        );
        $this->assertSame(
            'select * from "users" inner join "users_emails" on "users_emails"."userid" = "users"."id" where lower("users_emails"."email"::text) like lower(?)',
            $c->table('users')->join('users_emails', 'users_emails.userid', '=', 'users.id')
                ->where('users_emails.email', 'like', 'a%')->toSql()
        );
        $this->assertSame(
            'select * from "users" inner join "users_emails" as "e" on "e"."userid" = "users"."id" and lower("e"."email"::text) like lower(?)',
            $c->table('users')->join('users_emails as e', function ($join) {
                $join->on('e.userid', '=', 'users.id')->where('e.email', 'like', 'a%');
            })->toSql()
        );
    }

    /**
     * The cast to text is what lets LIKE work on a number or a JSON value, and a column that is not citext is
     * case-sensitive on PostgreSQL by design, so everything else renders as stock Laravel does.
     */
    public function test_like_on_any_other_column_keeps_the_stock_cast(): void
    {
        $c = $this->citextConnection();

        $this->assertSame(
            'select * from "messages" where "id"::text like ? and "subject"::text like ?',
            $c->table('messages')->where('id', 'like', '12%')->where('subject', 'like', 'a%')->toSql()
        );
        // Same column name, another table.
        $this->assertSame(
            'select * from "teams" where "name"::text like ?',
            $c->table('teams')->where('name', 'like', 'a%')->toSql()
        );
        // Not traceable to a table: a derived table.
        $this->assertSame(
            'select * from (select 1) t where "email"::text like ?',
            $c->table(DB::raw('(select 1) t'))->where('email', 'like', 'a%')->toSql()
        );
        // Without a database to read the catalogue from, nothing is treated as citext.
        $this->assertSame(
            'select * from "users_emails" where "email"::text like ?',
            (new JsonTextPostgresConnection(fn () => null))->table('users_emails')->where('email', 'like', 'a%')->toSql()
        );
    }

    public function test_like_on_a_case_insensitive_column_ignores_case(): void
    {

        $user = $this->createTestUser();
        $local = 'MixedCase'.uniqid();
        $id = DB::table('users_emails')->insertGetId(['userid' => $user->id, 'email' => $local.'-g123@user.trashnothing.com', 'preferred' => 0]);

        // users_emails.email ignores case on both engines: a _ci collation on MySQL, citext on PostgreSQL, where
        // the grammar reads the real catalogue. Only the row made
        // above is asked about: the test user has an address of its own as well.
        $query = fn (string $operator, string $pattern) => DB::table('users_emails')
            ->where('id', $id)
            ->where('email', $operator, $pattern);

        if ($this->onPostgres()) {
            $this->assertStringContainsString('lower("email"::text) LIKE lower(?)', $query('LIKE', 'x')->toSql());
        }
        $this->assertSame(1, $query('LIKE', strtolower($local).'-g%@user.trashnothing.com')->count());
        $this->assertSame(1, $query('LIKE', strtoupper($local).'-G%@USER.TRASHNOTHING.COM')->count());
        $this->assertSame(0, $query('LIKE', strtolower($local).'-h%')->count());
        $this->assertSame(0, $query('NOT LIKE', strtoupper($local).'-g%')->count());
        // An escaped wildcard is still a literal after lowering.
        $this->assertSame(0, $query('LIKE', strtolower($local).'\\%')->count());
    }

    public function test_index_hints_render_nothing_on_postgresql(): void
    {
        $connection = new JsonTextPostgresConnection(fn () => null);

        $this->assertSame(
            'select * from "users" where "id" = ?',
            $connection->table('users')->forceIndex('a')->ignoreIndex('b')->where('id', 1)->toSql()
        );
    }
}
