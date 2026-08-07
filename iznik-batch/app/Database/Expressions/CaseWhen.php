<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use LogicException;

/**
 * The searched CASE expression:
 *
 *   CASE WHEN cond1 THEN result1 [WHEN cond2 THEN result2 ...] [ELSE else] END
 *
 * Built fluently:
 *
 *   (new CaseWhen())
 *       ->when(new Comparison('status', '=', Value::of('open')))->then(Value::of('Open'))
 *       ->when(new Comparison('status', '=', Value::of('closed')))->then(Value::of('Closed'))
 *       ->otherwise(Value::of('Unknown'));
 *
 * Each `when()` condition must be a ConditionExpression - Comparison in this
 * namespace is the usual choice, and composes here because it implements
 * ConditionExpression rather than being tied to Query\Builder::where().
 *
 * `then()`/`otherwise()` results are rendered via RendersOperands: bare
 * strings are column names, Value::of()/nested Expressions embed as-is,
 * other scalars are literals.
 *
 * NOT BOUND - literal results are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class CaseWhen implements ExpressionContract
{
    use RendersOperands;

    /** @var array<int, array{condition: ConditionExpression, result: mixed}> */
    private array $whens = [];

    private bool $awaitingThen = false;

    /** @var mixed */
    private $else = null;

    private bool $hasElse = false;

    public function when(ConditionExpression $condition): self
    {
        if ($this->awaitingThen) {
            throw new LogicException('when() called again before the previous when() got a then().');
        }

        $this->whens[] = ['condition' => $condition, 'result' => null];
        $this->awaitingThen = true;

        return $this;
    }

    public function then(mixed $result): self
    {
        if (! $this->awaitingThen) {
            throw new LogicException('then() called without a preceding when().');
        }

        $this->whens[array_key_last($this->whens)]['result'] = $result;
        $this->awaitingThen = false;

        return $this;
    }

    public function otherwise(mixed $result): self
    {
        $this->else = $result;
        $this->hasElse = true;

        return $this;
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->whens === [] || $this->awaitingThen) {
            throw new LogicException('CaseWhen requires at least one complete when()->then() pair.');
        }

        $sql = 'CASE';

        foreach ($this->whens as $when) {
            $sql .= ' WHEN '.$when['condition']->getValue($grammar)
                .' THEN '.$this->renderOperand($when['result'], $grammar);
        }

        if ($this->hasElse) {
            $sql .= ' ELSE '.$this->renderOperand($this->else, $grammar);
        }

        return $sql.' END';
    }
}
