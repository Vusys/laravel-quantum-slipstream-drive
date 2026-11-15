<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent builder every HasIdentityMap model gets: Laravel's own,
 * with the identity map's query path from {@see InteractsWithIdentityMap}.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class IdentityMapBuilder extends Builder
{
    /** @use InteractsWithIdentityMap<TModel> */
    use InteractsWithIdentityMap;
}
