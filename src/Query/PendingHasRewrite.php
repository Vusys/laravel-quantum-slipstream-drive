<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;

final readonly class PendingHasRewrite
{
    public function __construct(
        public string $relation,
        public bool $not,
        public PredicateNode $innerPredicate,
        public int $whereOffset,
    ) {}
}
