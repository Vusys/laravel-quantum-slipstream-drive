<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A builder that narrows Eloquent's untyped return types, as
 * laravel-bitemporal's does with sole(): Model. Composing the identity map
 * onto it must not widen them back (a fatal error at class load).
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class NarrowingBuilder extends Builder
{
    #[\Override]
    public function sole($columns = ['*']): Model
    {
        return parent::sole($columns);
    }

    #[\Override]
    public function first($columns = ['*']): ?Model
    {
        return parent::first($columns);
    }

    /** @return array<int, TModel> */
    #[\Override]
    public function getModels($columns = ['*']): array
    {
        return parent::getModels($columns);
    }
}
