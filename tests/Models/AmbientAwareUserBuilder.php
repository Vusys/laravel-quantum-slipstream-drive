<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Query\InteractsWithIdentityMap;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\AmbientFilterBuilder;

/**
 * The ambient-filter builder composed the safe way: it stands the identity
 * map aside while its execution-time filter is on.
 *
 * @template TModel of Model
 *
 * @extends AmbientFilterBuilder<TModel>
 */
final class AmbientAwareUserBuilder extends AmbientFilterBuilder
{
    /** @use InteractsWithIdentityMap<TModel> */
    use InteractsWithIdentityMap {
        bypassesIdentityMap as private bypassesByDefault;
    }

    // No #[\Override]: this replaces the trait's method, not a parent's.
    protected function bypassesIdentityMap(): bool
    {
        return self::$onlyActive || $this->bypassesByDefault();
    }
}
