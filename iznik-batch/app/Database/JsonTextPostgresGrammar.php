<?php

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\JoinClause;
use PDO;
use Throwable;

/**
 * The query builder on PostgreSQL where it differs from stock Laravel.
 *
 * Index hints (forceIndex, ignoreIndex) are MySQL optimiser hints and render as nothing here.
 *
 * JSON paths (where('settings->key', ...)):
 * Several JSON columns (users.settings, groups.settings, memberships.settings) are text, as they were
 * stored on MySQL, and PostgreSQL's -> and ->> operators need json or jsonb. The column is cast to jsonb
 * first; for a column that is already jsonb the cast does nothing. MySQL is not affected.
 *
 * LIKE on a citext column (where('email', 'LIKE', ...)):
 * Stock Laravel renders every LIKE as "col"::text LIKE ?. For a citext column, which is what MySQL's
 * case-insensitive text became where the code relies on it, that cast makes the match case-sensitive, so
 * 'ES86 %' no longer finds 'es86 1aa' as it does on MySQL. It also rules out any index, and a plain citext
 * LIKE cannot use a btree either. So a LIKE or NOT LIKE on a citext column renders as
 *
 *     lower("col"::text) LIKE lower(?)
 *
 * which compares the way citext itself does (it lowers both sides) and, for a pattern that starts with
 * fixed text, can use an expression index on (lower(col::text)) text_pattern_ops. PostgreSQL plans a
 * statement Laravel has just prepared with the bound value in hand, so lower(?) is a constant by then.
 * Every other column keeps the stock rendering: the cast is what lets LIKE work on a number, a date or JSON.
 */
class JsonTextPostgresGrammar extends PostgresGrammar
{
    /**
     * The citext columns of the connection's schema, as [table => [column => true]]. Null until first needed.
     *
     * @var array<string, array<string, true>>|null
     */
    private ?array $citextColumns = null;

    /**
     * Sets the citext columns instead of reading them from the catalogue, for rendering without a database.
     *
     * @param  array<string, array<int, string>>  $columns  table => column names
     */
    public function useCitextColumns(array $columns): static
    {
        $this->citextColumns = array_map(fn (array $names) => array_fill_keys($names, true), $columns);

        return $this;
    }

    protected function whereBasic(Builder $query, $where)
    {
        $operator = strtolower(trim((string) $where['operator']));

        if (($operator === 'like' || $operator === 'not like')
            && is_string($where['column'])
            && $this->isCitextColumn($query, $where['column'])) {
            return sprintf(
                'lower(%s::text) %s lower(%s)',
                $this->wrap($where['column']),
                $where['operator'],
                $this->parameter($where['value'])
            );
        }

        return parent::whereBasic($query, $where);
    }

    /**
     * True when the column named in a where clause is a citext column of a table the query reads. Anything
     * that cannot be traced to a table (a JSON path, a derived table, an alias from an enclosing query) is
     * not, and keeps the stock rendering.
     */
    private function isCitextColumn(Builder $query, string $column): bool
    {
        if (str_contains($column, '->')) {
            return false;
        }

        $citext = $this->citextColumns();

        if (! $citext) {
            return false;
        }

        $segments = explode('.', $column);
        $name = array_pop($segments);
        $qualifier = $segments ? end($segments) : null;

        // The tables the query reads, by the name the column would be qualified with.
        $tables = [];
        $sources = [$query->from, $query instanceof JoinClause ? $query->table : null];
        foreach ($query->joins ?? [] as $join) {
            $sources[] = $join->table;
        }
        foreach ($sources as $source) {
            if (! is_string($source)) {
                continue;
            }
            $parts = preg_split('/\s+as\s+/i', trim($source));
            $tableParts = explode('.', $parts[0]);
            $table = end($tableParts);
            $tables[$parts[1] ?? $table] = $table;
        }

        if ($qualifier !== null) {
            // An alias the query declares, or else the table's own name (as in a join's ON clause).
            return isset($citext[$tables[$qualifier] ?? $qualifier][$name]);
        }

        foreach ($tables as $table) {
            if (isset($citext[$table][$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the schema's citext columns once per connection. It goes straight to PDO so that it does not
     * appear in the query log or fire query events, which callers and tests count. A failed read (no
     * connection yet, or a transaction PostgreSQL has aborted) is not remembered and gives the stock
     * rendering for this statement.
     *
     * @return array<string, array<string, true>>
     */
    private function citextColumns(): array
    {
        if ($this->citextColumns !== null) {
            return $this->citextColumns;
        }

        try {
            // keep-raw: PostgreSQL catalogue lookup of the citext columns, sent through PDO so it stays out of the query log.
            $rows = $this->connection->getPdo()->query(
                "SELECT c.relname AS table_name, a.attname AS column_name
                   FROM pg_attribute a
                   JOIN pg_class c ON c.oid = a.attrelid
                   JOIN pg_type t ON t.oid = a.atttypid
                  WHERE t.typname = 'citext'
                    AND c.relnamespace = CAST(quote_ident(current_schema()) AS regnamespace)
                    AND c.relkind IN ('r', 'p', 'v', 'm')
                    AND a.attnum > 0
                    AND NOT a.attisdropped"
            )->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as [$table, $name]) {
            $columns[$table][$name] = true;
        }

        return $this->citextColumns = $columns;
    }

    /**
     * PostgreSQL timestamps keep microseconds (MySQL DATETIME columns here hold whole seconds), so a bound
     * date keeps them too: otherwise a value stored from NOW() compares as later than the same instant bound
     * from PHP, which has been truncated to the second.
     */
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s.u';
    }

    protected function compileIndexHint(Builder $query, $indexHint)
    {
        return '';
    }

    protected function wrapJsonSelector($value)
    {
        $path = explode('->', $value);

        $field = $this->wrapSegments(explode('.', array_shift($path))).'::jsonb';

        $wrappedPath = $this->wrapJsonPathAttributes($path);

        $attribute = array_pop($wrappedPath);

        if (! empty($wrappedPath)) {
            return $field.'->'.implode('->', $wrappedPath).'->>'.$attribute;
        }

        return $field.'->>'.$attribute;
    }
}
