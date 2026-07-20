<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Predicate;

final readonly class NullNode implements PredicateNode
{
    public function __construct(
        public string $column,
        public bool $negated,
    ) {}
}
