<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Stands in for another package's model trait, which claims the model's
 * builder the same way HasIdentityMap does.
 */
trait UsesThirdPartyBuilder
{
    /**
     * @param  QueryBuilder  $query
     * @return ThirdPartyBuilder<static>
     */
    public function newEloquentBuilder($query): ThirdPartyBuilder
    {
        return new ThirdPartyBuilder($query);
    }
}
