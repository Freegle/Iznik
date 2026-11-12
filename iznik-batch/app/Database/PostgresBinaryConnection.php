<?php

namespace App\Database;

use Generator;
use Illuminate\Database\PostgresConnection;
use PDO;

/**
 * PostgreSQL connection that handles binary data the way the MySQL driver does, so code written against a
 * blob column works unchanged on a bytea column.
 *
 * Writing: a string that is not text (it holds a NUL byte or is not valid UTF-8) is sent as a binary
 * parameter. PostgreSQL refuses it as text. MySQL takes any bytes in a string parameter.
 *
 * Reading: PDO's PostgreSQL driver returns a bytea value as a stream resource, where MySQL's returns a string.
 * The value is read into a string before the row reaches the caller.
 */
class PostgresBinaryConnection extends PostgresConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new PostgresTextJsonGrammar($this);
    }

    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_resource($value) => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            };

            if (is_string($value) && self::isBinary($value)) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $value);
                rewind($stream);
                $value = $stream;
                $type = PDO::PARAM_LOB;
            }

            $statement->bindValue(is_string($key) ? $key : $key + 1, $value, $type);
        }
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return array_map(fn ($row) => $this->readStreams($row), parent::select($query, $bindings, $useReadPdo));
    }

    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        return (function () use ($query, $bindings, $useReadPdo): Generator {
            foreach (parent::cursor($query, $bindings, $useReadPdo) as $row) {
                yield $this->readStreams($row);
            }
        })();
    }

    /** True when the string cannot go to PostgreSQL as text. */
    private static function isBinary(string $value): bool
    {
        return str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8');
    }

    /** Replaces any stream in the row with its contents. */
    private function readStreams(mixed $row): mixed
    {
        if (is_object($row)) {
            foreach (get_object_vars($row) as $name => $value) {
                if (is_resource($value)) {
                    $row->$name = stream_get_contents($value);
                }
            }

            return $row;
        }

        if (is_array($row)) {
            foreach ($row as $name => $value) {
                if (is_resource($value)) {
                    $row[$name] = stream_get_contents($value);
                }
            }
        }

        return $row;
    }
}
