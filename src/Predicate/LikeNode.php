<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Predicate;

final readonly class LikeNode implements PredicateNode
{
    public function __construct(
        public string $column,
        public string $pattern,
        public bool $negated,
    ) {}
}
