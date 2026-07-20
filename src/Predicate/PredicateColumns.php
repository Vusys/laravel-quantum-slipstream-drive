<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Predicate;

final class PredicateColumns
{
    /** @return list<string> */
    public static function fromNode(PredicateNode $node): array
    {
        return match (true) {
            $node instanceof AndNode, $node instanceof OrNode => array_values(array_unique(
                array_merge([], ...array_map(self::fromNode(...), $node->children))
            )),
            $node instanceof ComparisonNode => [$node->column],
            $node instanceof LikeNode => [$node->column],
            $node instanceof InNode => [$node->column],
            $node instanceof NullNode => [$node->column],
            $node instanceof BetweenNode => [$node->column],
            default => [],
        };
    }
}
