<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Enums\FactConfidence;
use Vusys\QuantumSlipstreamDrive\Enums\FactSource;
use Vusys\QuantumSlipstreamDrive\Enums\PlanType;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\Query\IdentityMapBuilder;
use Vusys\QuantumSlipstreamDrive\Store\IdentityEntry;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;

final readonly class ColumnBackfiller
{
    public function __construct(private IdentityMapStore $store) {}

    public function isEnabled(): bool
    {
        return config('quantum-slipstream-drive.partial_models') === 'backfill_missing_columns';
    }

    /**
     * Identify columns the requested list contains that aren't yet known on the entry.
     *
     * '*' always returns []: when the caller wants all columns we can never narrow the
     * fetch, so backfill cannot help. Coverage / full re-fetch is the right path for *.
     *
     * @param  list<string>|array<int, string>  $requested
     * @return list<string>
     */
    public function missingColumns(IdentityEntry $entry, array $requested): array
    {
        if ($requested === [] || in_array('*', $requested, true)) {
            return [];
        }

        $missing = [];

        foreach ($requested as $column) {
            if ($column === '') {
                continue;
            }

            if (! $entry->attributes->knows($column)) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /**
     * Run a narrow SELECT for the missing columns and merge into the cached model.
     *
     * Returns true when the merge succeeded and the entry now satisfies the request.
     * Returns false if the row no longer exists (delete race) or the merge could not
     * cover the requested columns — caller must fall through to SQL.
     *
     * Dirty in-memory attributes are preserved: for any column whose cached model
     * value differs from the original (i.e. {@see Model::isDirty()} returns true),
     * the fetched value is recorded as the new original but the current value is
     * left alone.
     *
     * @param  list<string>  $missingColumns
     */
    public function backfill(IdentityEntry $entry, array $missingColumns): bool
    {
        if ($missingColumns === []) {
            return true;
        }

        $modelClass = $entry->modelClass;

        if (! is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        $primaryKeyName = $entry->primaryKeyName;
        $columnsToFetch = $missingColumns;

        if (! in_array($primaryKeyName, $columnsToFetch, true)) {
            array_unshift($columnsToFetch, $primaryKeyName);
        }

        $primaryKeyValue = $entry->primaryKeyValue;

        $fresh = $this->store->disabled(static function () use ($modelClass, $primaryKeyValue, $columnsToFetch): ?Model {
            $builder = $modelClass::query();

            if ($builder instanceof IdentityMapBuilder) {
                $builder = $builder->withoutIdentityMap();
            }

            $result = $builder->whereKey($primaryKeyValue)->first($columnsToFetch);

            return $result instanceof Model ? $result : null;
        });

        $this->store->capture(new Explanation(
            type: PlanType::BackfillColumnsFromDatabase,
            modelClass: $modelClass,
            reason: 'partial-model-narrow-fetch',
            sqlExecuted: true,
            missingKeys: $missingColumns,
            memoryKeys: [$primaryKeyValue],
        ));

        if (! $fresh instanceof Model) {
            $this->store->forget($entry->model);

            return false;
        }

        $this->merge($entry, $fresh);

        return $entry->attributes->satisfies($missingColumns);
    }

    private function merge(IdentityEntry $entry, Model $fresh): void
    {
        $cachedModel = $entry->model;
        $freshAttrs = $fresh->getAttributes();

        $rawAttrs = $cachedModel->getAttributes();
        $dirtyOverrides = [];

        foreach ($freshAttrs as $column => $fetchedValue) {
            if ($cachedModel->isDirty($column)) {
                $dirtyOverrides[$column] = $rawAttrs[$column] ?? null;
            }

            $rawAttrs[$column] = $fetchedValue;
        }

        $cachedModel->setRawAttributes($rawAttrs, false);

        foreach (array_keys($freshAttrs) as $column) {
            $cachedModel->syncOriginalAttribute((string) $column);
        }

        if ($dirtyOverrides !== []) {
            $rawAttrs = $cachedModel->getAttributes();

            foreach ($dirtyOverrides as $column => $dirtyValue) {
                $rawAttrs[$column] = $dirtyValue;
            }

            $cachedModel->setRawAttributes($rawAttrs, false);
        }

        foreach ($freshAttrs as $column => $fetchedValue) {
            $column = (string) $column;
            $isDirty = array_key_exists($column, $dirtyOverrides);
            $currentValue = $isDirty ? $dirtyOverrides[$column] : $fetchedValue;
            $existing = $entry->attributes->get($column);

            if ($existing instanceof AttributeFact) {
                $existing->originalValue = $fetchedValue;
                $existing->currentValue = $currentValue;
                $existing->isDirty = $isDirty;
                $existing->confidence = FactConfidence::Certain;
                $existing->source = FactSource::HydratedFromDatabase;
            } else {
                $entry->attributes->set($column, new AttributeFact(
                    column: $column,
                    originalValue: $fetchedValue,
                    currentValue: $currentValue,
                    isDirty: $isDirty,
                    confidence: FactConfidence::Certain,
                    source: FactSource::HydratedFromDatabase,
                ));
            }
        }

        $entry->version++;
    }
}
