<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * CRC32(operand) - MySQL's 32-bit cyclic redundancy checksum, used in this
 * codebase as a uniform-spread sharding hash (e.g.
 * `CRC32(users.id) % shards = shard`, via Arithmetic) where a plain MOD() on
 * the id column itself would skew under Galera/Percona's auto_increment
 * stride (see the call site's own comment for why).
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Crc32 implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        $operand = $this->renderOperand($this->operand, $grammar);

        // The stored crc32() on PostgreSQL takes text; MySQL checksums a number's decimal text, so cast it the same way.
        return $this->isPostgres($grammar) ? 'CRC32(CAST('.$operand.' AS text))' : 'CRC32('.$operand.')';
    }
}
