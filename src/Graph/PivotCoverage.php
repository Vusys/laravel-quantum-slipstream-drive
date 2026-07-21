<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

use Vusys\QuantumSlipstreamDrive\Coverage\SubsetChecker;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;

final readonly class PivotCoverage
{
    /**
     * @param  list<string>  $knownPivotColumns  columns whose value is recorded on every edge
     * @param  PredicateNode|null  $predicate  the load predicate under which the related rows were
     *                                         fetched. `null` (with `complete: true`) means an unfiltered load that
     *                                         covers every related row; a non-null predicate means the coverage is
     *                                         complete only for rows satisfying it, so a later read may reuse it only
     *                                         when its own predicate is provably a subset (via {@see SubsetChecker}).
     *                                         Pivot-column terms in the predicate stay qualified with the pivot table
     *                                         name so they never collide with same-named related-model columns.
     * @param  list<PivotEdge>  $filteredEdges  the edge set captured under a non-null predicate. Empty for
     *                                          unfiltered coverage, whose edges live in the shared pivot-edge bucket.
     */
    public function __construct(
        public ModelIdentity $parent,
        public string $relationName,
        public string $relatedModelClass,
        public string $pivotTable,
        public bool $complete,
        public array $knownPivotColumns,
        public ?PredicateNode $predicate = null,
        public array $filteredEdges = [],
    ) {}
}
