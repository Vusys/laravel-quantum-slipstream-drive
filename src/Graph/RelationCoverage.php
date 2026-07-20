<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

use Vusys\QuantumSlipstreamDrive\Coverage\ColumnSet;
use Vusys\QuantumSlipstreamDrive\Coverage\SubsetChecker;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;

final readonly class RelationCoverage
{
    /**
     * @param  list<int|string>  $childPrimaryKeys
     * @param  PredicateNode|null  $predicate  the load predicate under which the
     *                                         children were fetched. `null` means an unfiltered load that covers every
     *                                         related row; a non-null predicate means the coverage is complete only for
     *                                         rows satisfying it, so a later read may reuse it only when its own
     *                                         predicate is provably a subset (via {@see SubsetChecker}).
     */
    public function __construct(
        public ModelIdentity $parent,
        public string $relationName,
        public string $relatedModelClass,
        public bool $complete,
        public ColumnSet $columns,
        public array $childPrimaryKeys,
        public ?PredicateNode $predicate = null,
    ) {}
}
