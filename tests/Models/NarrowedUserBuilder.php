<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Query\InteractsWithIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\NarrowingBuilder;

/**
 * @template TModel of Model
 *
 * @extends NarrowingBuilder<TModel>
 */
final class NarrowedUserBuilder extends NarrowingBuilder
{
    /** @use InteractsWithIdentityMap<TModel> */
    use InteractsWithIdentityMap;
}
