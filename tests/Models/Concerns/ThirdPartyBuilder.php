<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for another package's builder (a nested set's TreeQueryBuilder,
 * say): a Builder subclass with scopes of its own, and a get() it overrides.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ThirdPartyBuilder extends Builder
{
    public static int $gets = 0;

    /** @return $this */
    public function active(): static
    {
        return $this->where('active', true);
    }

    #[\Override]
    public function get($columns = ['*'])
    {
        self::$gets++;

        return parent::get($columns);
    }
}
