<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * SHA2(string, bits) - the hash as lowercase hexadecimal text. Bits are 224, 256, 384 or 512. On PostgreSQL the
 * string is hashed as UTF-8 bytes, as MySQL hashes its utf8mb4 bytes.
 */
final class Sha2 implements ExpressionContract
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(private readonly mixed $operand, private readonly int $bits = 256)
    {
        if (! in_array($bits, [224, 256, 384, 512], true)) {
            throw new InvalidArgumentException("SHA2 bit length [{$bits}] is not one of 224, 256, 384, 512.");
        }
    }

    public function getValue(Grammar $grammar): string
    {
        $operand = $this->renderOperand($this->operand, $grammar);

        if ($this->isPostgres($grammar)) {
            return "encode(sha{$this->bits}(convert_to(CAST({$operand} AS text), 'UTF8')), 'hex')";
        }

        return "SHA2({$operand}, {$this->bits})";
    }
}
