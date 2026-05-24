<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

final readonly class BetweenNode implements PredicateNode
{
    public function __construct(
        public string $column,
        public mixed $min,
        public mixed $max,
        public bool $negated,
    ) {}
}
