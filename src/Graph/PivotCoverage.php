<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

final readonly class PivotCoverage
{
    /**
     * @param  list<string>  $knownPivotColumns  columns whose value is recorded on every edge
     */
    public function __construct(
        public ModelIdentity $parent,
        public string $relationName,
        public string $relatedModelClass,
        public string $pivotTable,
        public bool $complete,
        public array $knownPivotColumns,
    ) {}
}
