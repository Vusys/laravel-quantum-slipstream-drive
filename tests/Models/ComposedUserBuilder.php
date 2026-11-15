<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Query\InteractsWithIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\ThirdPartyBuilder;

/**
 * @template TModel of Model
 *
 * @extends ThirdPartyBuilder<TModel>
 */
final class ComposedUserBuilder extends ThirdPartyBuilder
{
    /** @use InteractsWithIdentityMap<TModel> */
    use InteractsWithIdentityMap;
}
