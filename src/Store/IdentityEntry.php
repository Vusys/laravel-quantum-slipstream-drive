<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Store;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Enums\LifecycleState;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeKnowledge;
use Vusys\QuantumSlipstreamDrive\Knowledge\RelationKnowledge;

final class IdentityEntry
{
    public function __construct(
        public readonly string $connection,
        public readonly string $modelClass,
        public readonly string $table,
        public readonly string $primaryKeyName,
        public readonly int|string $primaryKeyValue,
        public readonly string $scopeFingerprint,
        public Model $model,
        public AttributeKnowledge $attributes,
        public RelationKnowledge $relations,
        public LifecycleState $state,
        public int $version,
    ) {}

    public function __clone(): void
    {
        $this->attributes = clone $this->attributes;
        $this->relations = clone $this->relations;
    }
}
