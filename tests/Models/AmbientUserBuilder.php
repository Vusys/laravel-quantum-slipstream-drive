<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Query\InteractsWithIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\AmbientFilterBuilder;

/**
 * @template TModel of Model
 *
 * @extends AmbientFilterBuilder<TModel>
 */
final class AmbientUserBuilder extends AmbientFilterBuilder
{
    /** @use InteractsWithIdentityMap<TModel> */
    use InteractsWithIdentityMap;
}
