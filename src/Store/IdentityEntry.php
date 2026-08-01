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
        /**
         * Database-native full-row snapshot for raw `DB::table()` read serving,
         * or null when none was captured. Taken verbatim from a genuine
         * full-column SELECT hydration (pre-cast attribute values, every column
         * present), so it replays byte-identically to a bypassed raw query.
         *
         * @var array<string, mixed>|null
         */
        public ?array $rawRow = null,
        /**
         * The entry {@see $version} at the moment {@see $rawRow} was captured.
         * The snapshot is only served while this still equals the live version:
         * any mutation bumps the version, so a stale snapshot is never returned.
         */
        public int $rawRowVersion = 0,
        /**
         * Recency stamp from the store's monotonic access clock, bumped on
         * every read or write that proves the entry is hot. Cap-breach
         * eviction drops the entries with the lowest stamps first.
         */
        public int $lastTouched = 0,
    ) {}

    public function __clone(): void
    {
        $this->attributes = clone $this->attributes;
        $this->relations = clone $this->relations;
    }
}
