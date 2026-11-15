<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A builder that adds a constraint at execution time rather than as a clause
 * — the shape of an "as of" lens. The identity map, answering before
 * getModels() runs, can't see it: the hazard the oracle helper exists to
 * catch.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AmbientFilterBuilder extends Builder
{
    public static bool $onlyActive = false;

    #[\Override]
    public function getModels($columns = ['*'])
    {
        if (self::$onlyActive) {
            $this->where('active', true);
        }

        return parent::getModels($columns);
    }
}
