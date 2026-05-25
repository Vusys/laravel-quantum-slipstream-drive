<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

/**
 * Default fallback profile when no driver-specific semantics are wired.
 *
 * Step 1 of M11: mirrors today's PHP loose-equality behaviour so the
 * interface refactor is a no-op for existing tests. Step 3 introduces
 * per-driver profiles ({@see SqliteSemantics}, {@see MySqlSemantics}…)
 * that resolve confidently using ColumnSemantics, and this profile will
 * be tightened to return Unknown for anything not provably safe.
 */
final class ConservativeSemantics implements DriverSemantics
{
    #[\Override]
    public function compare(mixed $left, string $operator, mixed $right, ColumnSemantics $column): EvaluationResult
    {
        if ($left === null || $right === null) {
            return EvaluationResult::Unknown;
        }

        // phpcs:disable SlevomatCodingStandard.Operators.DisallowEqualOperators
        if ($operator === '=') {
            return $left == $right ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        if ($operator === '!=' || $operator === '<>') {
            return $left != $right ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        return $this->compareOrdered($left, $operator, $right);
        // phpcs:enable
    }

    #[\Override]
    public function compareForOrder(mixed $left, mixed $right, ColumnSemantics $column): ?int
    {
        if ((! is_int($left) && ! is_float($left)) || (! is_int($right) && ! is_float($right))) {
            return null;
        }

        return $left <=> $right;
    }

    #[\Override]
    public function nullOrdering(string $direction): NullOrdering
    {
        return strtolower($direction) === 'desc' ? NullOrdering::NullsFirst : NullOrdering::NullsLast;
    }

    /** @param '>'|'>='|'<'|'<=' $operator */
    private function compareOrdered(mixed $left, string $operator, mixed $right): EvaluationResult
    {
        if ((! is_int($left) && ! is_float($left)) || (! is_int($right) && ! is_float($right))) {
            return EvaluationResult::Unknown;
        }

        $matched = match ($operator) {
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
        };

        return $matched ? EvaluationResult::Match : EvaluationResult::Reject;
    }
}
