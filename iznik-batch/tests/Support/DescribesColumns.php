<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reads a column's definition from the database the suite is running on, in terms that mean the same on both
 * engines, so a schema test asserts one thing and runs on MySQL and PostgreSQL.
 *
 * The two engines hold the same column differently. A MySQL ENUM is a text column with a CHECK constraint on
 * PostgreSQL, named <table>_<column>_check, and a MySQL tinyint(1) flag is a smallint (tools/pg-schema).
 */
trait DescribesColumns
{
    /**
     * @return array{flag: bool, nullable: bool, default: ?string, values: ?list<string>}|null  null when the
     *         column does not exist. 'values' is the list an ENUM allows, in its declared order, or null for a
     *         column that is not one.
     */
    protected function describeColumn(string $table, string $column): ?array
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $this->describePostgresColumn($table, $column)
            : $this->describeMySqlColumn($table, $column);
    }

    private function describeMySqlColumn(string $table, string $column): ?array
    {
        $col = DB::selectOne('SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n, COLUMN_DEFAULT AS d
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
        if ($col === null) {
            return null;
        }

        $values = null;
        if (preg_match('/^enum\((.*)\)$/s', $col->t, $m) === 1) {
            preg_match_all("/'((?:[^']|'')*)'/", $m[1], $quoted);
            $values = array_map(fn (string $v) => str_replace("''", "'", $v), $quoted[1]);
        }

        return [
            'flag' => $col->t === 'tinyint(1)',
            'nullable' => $col->n === 'YES',
            'default' => $col->d === null ? null : (string) $col->d,
            'values' => $values,
        ];
    }

    private function describePostgresColumn(string $table, string $column): ?array
    {
        $col = DB::selectOne('SELECT data_type AS t, is_nullable AS n, column_default AS d
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, $column]);
        if ($col === null) {
            return null;
        }

        // A default reads back as an expression: 'unknown'::text, or a bare number.
        $default = $col->d;
        if ($default !== null && preg_match("/^'((?:[^']|'')*)'::[a-z ]+$/", $default, $m) === 1) {
            $default = str_replace("''", "'", $m[1]);
        }

        $values = null;
        $check = DB::selectOne('SELECT pg_get_constraintdef(c.oid) AS def
            FROM pg_constraint c
            JOIN pg_class r ON r.oid = c.conrelid
            JOIN pg_namespace s ON s.oid = r.relnamespace
            WHERE s.nspname = current_schema() AND r.relname = ? AND c.conname = ? AND c.contype = ?',
            [$table, "{$table}_{$column}_check", 'c']);
        if ($check !== null) {
            preg_match_all("/'((?:[^']|'')*)'::text/", $check->def, $quoted);
            $values = array_map(fn (string $v) => str_replace("''", "'", $v), $quoted[1]);
        }

        return [
            'flag' => $col->t === 'smallint',
            'nullable' => $col->n === 'YES',
            'default' => $default === null ? null : (string) $default,
            'values' => $values,
        ];
    }
}
