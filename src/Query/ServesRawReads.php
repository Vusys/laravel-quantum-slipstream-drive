<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Mixed into the per-driver connection subclasses so every base query builder
 * they hand out is a {@see RawServingQueryBuilder}. Only the builder class
 * changes; grammar and post-processor are the driver's own, so non-served
 * queries behave exactly as stock.
 */
trait ServesRawReads
{
    #[\Override]
    public function query(): QueryBuilder
    {
        return new RawServingQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
