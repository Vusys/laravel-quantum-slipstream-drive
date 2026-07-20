<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Coverage;

use Vusys\QuantumSlipstreamDrive\Predicate\AndNode;
use Vusys\QuantumSlipstreamDrive\Predicate\ComparisonNode;
use Vusys\QuantumSlipstreamDrive\Predicate\InNode;
use Vusys\QuantumSlipstreamDrive\Predicate\LikeNode;
use Vusys\QuantumSlipstreamDrive\Predicate\NullNode;
use Vusys\QuantumSlipstreamDrive\Predicate\OrNode;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;

final class SubsetChecker
{
    /**
     * Returns true if every row satisfying $query also satisfies $recorded.
     *
     * Conservatively returns false when the relationship cannot be proven from
     * the phase-one predicate node types (=, !=, IN, NOT IN, IS NULL, IS NOT NULL)
     * plus OR composition. Range comparisons and unsupported operators yield false.
     */
    public function isSubset(PredicateNode $query, PredicateNode $recorded): bool
    {
        // R2 ⊆ AND(D1, D2, ...) iff R2 ⊆ D1 AND R2 ⊆ D2 AND ...
        // Empty AND = tautology (matches everything) — any query is a subset.
        if ($recorded instanceof AndNode) {
            foreach ($recorded->children as $child) {
                if (! $this->isSubset($query, $child)) {
                    return false;
                }
            }

            return true;
        }

        // Q ⊆ OR(D1, D2, ...) if Q ⊆ Di for some branch (sufficient, not necessary).
        if ($recorded instanceof OrNode) {
            foreach ($recorded->children as $child) {
                if ($this->isSubset($query, $child)) {
                    return true;
                }
            }

            return false;
        }

        // OR(C1, C2, ...) ⊆ R1 iff every branch Ci ⊆ R1 (the union stays inside R1).
        if ($query instanceof OrNode) {
            foreach ($query->children as $child) {
                if (! $this->isSubset($child, $recorded)) {
                    return false;
                }
            }

            return true;
        }

        // AND(C1, C2, ...) ⊆ R1 if any Ci ⊆ R1 (AND adds constraints, only narrows).
        if ($query instanceof AndNode) {
            foreach ($query->children as $child) {
                if ($this->isSubset($child, $recorded)) {
                    return true;
                }
            }

            return false;
        }

        if ($query instanceof ComparisonNode && $recorded instanceof ComparisonNode) {
            return $this->comparisonSubset($query, $recorded);
        }

        // col = v ⊆ col IN (v, ...) when recorded is non-negated
        if ($query instanceof ComparisonNode && $recorded instanceof InNode) {
            return ! $recorded->negated
                && $query->operator === '='
                && $query->column === $recorded->column
                && $this->inLoose($query->value, $recorded->values);
        }

        // col IN (s) ⊆ col IN (t) when both non-negated and s ⊆ t
        if ($query instanceof InNode && $recorded instanceof InNode) {
            if ($query->negated || $recorded->negated || $query->column !== $recorded->column) {
                return false;
            }

            foreach ($query->values as $v) {
                if (! $this->inLoose($v, $recorded->values)) {
                    return false;
                }
            }

            return true;
        }

        // col IN (v) ⊆ col = v  (single-element non-negated IN)
        if ($query instanceof InNode && $recorded instanceof ComparisonNode) {
            return ! $query->negated
                && $recorded->operator === '='
                && $query->column === $recorded->column
                && count($query->values) === 1
                && $this->looseEquals($query->values[0], $recorded->value);
        }

        if ($query instanceof NullNode && $recorded instanceof NullNode) {
            return $query->column === $recorded->column && $query->negated === $recorded->negated;
        }

        // An identical LIKE predicate is trivially a subset of itself; anything
        // else about pattern containment is left to SQL.
        if ($query instanceof LikeNode && $recorded instanceof LikeNode) {
            return $query->column === $recorded->column
                && $query->pattern === $recorded->pattern
                && $query->negated === $recorded->negated;
        }

        return false;
    }

    private function comparisonSubset(ComparisonNode $query, ComparisonNode $recorded): bool
    {
        if ($query->column !== $recorded->column) {
            return false;
        }

        $qOp = $query->operator === '<>' ? '!=' : $query->operator;
        $rOp = $recorded->operator === '<>' ? '!=' : $recorded->operator;

        if ($qOp !== $rOp) {
            return false;
        }

        return $this->looseEquals($query->value, $recorded->value);
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
        return $a == $b;
    }

    /** @param list<mixed> $haystack */
    private function inLoose(mixed $needle, array $haystack): bool
    {
        foreach ($haystack as $v) {
            if ($this->looseEquals($needle, $v)) {
                return true;
            }
        }

        return false;
    }
}
