<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Predicate;

final readonly class AndNode implements PredicateNode
{
    /** @param list<PredicateNode> $children */
    public function __construct(public array $children) {}
}
