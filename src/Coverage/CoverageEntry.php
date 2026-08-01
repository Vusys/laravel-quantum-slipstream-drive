<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Coverage;

use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;

final class CoverageEntry
{
    /**
     * Recency stamp from the registry's monotonic access clock, bumped when
     * the entry is recorded or serves a query. Cap-breach eviction drops the
     * entries with the lowest stamps first.
     */
    public int $lastTouched = 0;

    /**
     * @param  list<int|string>  $primaryKeys
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $connection,
        public readonly string $table,
        public readonly string $scopeFingerprint,
        public readonly PredicateNode $region,
        public readonly ColumnSet $columns,
        public readonly array $primaryKeys,
        public readonly bool $complete,
        public readonly int $version,
    ) {}
}
