<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Store;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Enums\EvaluationResult;
use Vusys\QuantumSlipstreamDrive\Enums\FactConfidence;
use Vusys\QuantumSlipstreamDrive\Enums\FactSource;
use Vusys\QuantumSlipstreamDrive\Enums\LifecycleState;
use Vusys\QuantumSlipstreamDrive\Events\QueryDecided;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeFact;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeKnowledge;
use Vusys\QuantumSlipstreamDrive\Knowledge\RelationKnowledge;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateEvaluator;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;
use Vusys\QuantumSlipstreamDrive\Query\ModelMetadata;
use Vusys\QuantumSlipstreamDrive\Query\ScopeFingerprinter;
use Vusys\QuantumSlipstreamDrive\Schema\SchemaDiscovery;
use Vusys\QuantumSlipstreamDrive\Support\EvictionBatch;

final class IdentityMapStore
{
    /** @var array<string, IdentityEntry> */
    private array $entries = [];

    /**
     * Absence markers, keyed like $entries. The value is the recency stamp
     * from {@see $clock} at the last record or hit, so cap-breach eviction
     * can drop the coldest markers first. Presence of the key is the fact;
     * the stamp carries no meaning beyond eviction ordering.
     *
     * @var array<string, int>
     */
    private array $absent = [];

    /**
     * Monotonic access clock shared by entries and absence markers. Bumped on
     * every touch; never reset except by flush(), so relative order is all it
     * guarantees.
     */
    private int $clock = 0;

    /**
     * Reverse-index: modelClass → set of mapKeys.
     *
     * Maintained at insert and pruned by cap-breach eviction, but not by
     * every individual unset, so entries may name keys that are no longer in
     * $entries / $absent. Used by flush(modelClass) to avoid scanning every
     * key in the maps. A stale entry is a harmless no-op unset; full flush()
     * resets both indexes.
     *
     * @var array<string, array<string, true>>
     */
    private array $entryKeysByClass = [];

    /** @var array<string, array<string, true>> */
    private array $absentKeysByClass = [];

    /**
     * Reverse-index for raw-row serving: physical row key
     * ("{connection}|{table}|{pkName}|{pkValue}") → set of mapKeys whose entry
     * may hold a native row snapshot for that physical row. A physical row can
     * back several mapKeys (one per scope fingerprint). Like
     * {@see $entryKeysByClass} the index is maintained at capture and may name
     * stale mapKeys; {@see findRawRow()} validates each candidate against the
     * live entry (state + version) before serving, so a stale pointer is a
     * harmless miss, never a stale read.
     *
     * @var array<string, array<string, true>>
     */
    private array $rawRowIndex = [];

    /**
     * Cached raw_reads.enabled flag, populated lazily and reset by flush() so a
     * config change between tests is picked up. Gates the (per-full-read)
     * snapshot capture so a disabled feature costs nothing.
     */
    private ?bool $rawReadsEnabled = null;

    private readonly UniqueKeyIndex $uniqueKeyIndex;

    private bool $disabled = false;

    private ?string $pendingFingerprint = null;

    private bool $capturing = false;

    /** @var list<Explanation> */
    private array $captured = [];

    /**
     * Cached observability.enabled flag. Populated lazily on first capture()
     * call, reset to null by flush() so tests that mutate config between
     * cases see fresh state. config() is otherwise hit on every captured
     * decision, which streaming-disabled benches showed dominating per-find
     * cost.
     */
    private ?bool $observabilityEnabled = null;

    public function __construct(
        private readonly ?TransactionJournal $journal = null,
        /** @var int|null null disables the cap on $entries + $absent */
        private readonly ?int $maxEntries = null,
        ?int $maxUniqueKeys = null,
    ) {
        $this->uniqueKeyIndex = new UniqueKeyIndex($maxUniqueKeys);
    }

    private function atEntryCap(): bool
    {
        return $this->maxEntries !== null
            && (count($this->entries) + count($this->absent)) >= $this->maxEntries;
    }

    private function touchEntry(?IdentityEntry $entry): ?IdentityEntry
    {
        if ($entry instanceof IdentityEntry) {
            $entry->lastTouched = ++$this->clock;
        }

        return $entry;
    }

    /**
     * Shed the least-recently-touched tenth of the combined entry + absence
     * budget instead of flushing the whole store.
     *
     * Partial eviction is safe because nothing serves an answer from a
     * dangling reference: coverage regions and relation coverage re-resolve
     * every recorded primary key against the live store at serve time and
     * fall through to SQL when one is missing; unique-key and raw-row index
     * pointers are validated (and lazily pruned) on lookup. Dropping an
     * entry or an absence marker therefore only ever costs a query — never a
     * wrong answer. Coverage entries that referenced an evicted row are
     * pruned eagerly anyway: they could no longer serve anything, and would
     * otherwise linger as dead weight against the registry's own cap.
     */
    private function evictColdest(): void
    {
        if ($this->maxEntries === null) {
            return;
        }

        // A soft-deleted key can be present in both maps at once; max() keeps
        // it hot as long as either side is.
        $stamps = [];

        foreach ($this->entries as $mapKey => $entry) {
            $stamps[$mapKey] = $entry->lastTouched;
        }

        foreach ($this->absent as $mapKey => $stamp) {
            $stamps[$mapKey] = max($stamps[$mapKey] ?? 0, $stamp);
        }

        asort($stamps);

        $evictedPksByClass = [];

        foreach (array_slice(array_keys($stamps), 0, EvictionBatch::size($this->maxEntries)) as $mapKey) {
            $entry = $this->entries[$mapKey] ?? null;

            if ($entry instanceof IdentityEntry) {
                $evictedPksByClass[$entry->modelClass][$entry->primaryKeyValue] = true;
                unset($this->entryKeysByClass[$entry->modelClass][$mapKey]);
                unset($this->rawRowIndex[$this->makePhysicalKey(
                    $entry->connection,
                    $entry->table,
                    $entry->primaryKeyName,
                    $entry->primaryKeyValue,
                )][$mapKey]);
            }

            if (isset($this->absent[$mapKey])) {
                // mapKey format: "{connection}|{modelClass}|..."
                $parts = explode('|', $mapKey, 3);

                if (isset($parts[1])) {
                    unset($this->absentKeysByClass[$parts[1]][$mapKey]);
                }
            }

            unset($this->entries[$mapKey], $this->absent[$mapKey]);
        }

        if ($evictedPksByClass !== []) {
            resolve(CoverageRegistry::class)->evictReferencing($evictedPksByClass);
        }
    }

    public function setPendingFingerprint(?string $fingerprint): void
    {
        $this->pendingFingerprint = $fingerprint;
    }

    public function remember(Model $model, bool $allColumnsKnown = false): void
    {
        if ($this->disabled) {
            return;
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        if (! $model->exists) {
            return;
        }

        $fingerprint = $this->pendingFingerprint ?? ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        $this->snapshotForJournal($mapKey);

        if (isset($this->entries[$mapKey])) {
            $entry = $this->entries[$mapKey];
            $entry->lastTouched = ++$this->clock;

            // A partial-column hydration (pluck / select(subset)) must not replace
            // a richer cached instance with a narrower one — that would drop columns
            // while recordFromModel() keeps allColumnsKnown=true, so a later ['*']
            // read would serve a model missing attributes. Merge fresh values into
            // the canonical instance instead of downgrading it.
            $droppedColumns = array_diff_key($entry->model->getAttributes(), $model->getAttributes());

            if ($droppedColumns !== []) {
                $entry->model->setRawAttributes(
                    array_merge($entry->model->getAttributes(), $model->getAttributes()),
                    true,
                );
            } else {
                $entry->model = $model;
            }

            $entry->version++;
            $entry->attributes->recordFromModel($model, $allColumnsKnown);
        } else {
            // Promoting an existing absent marker to a live entry is net-zero
            // (the marker is unset below), so only a genuinely new key counts
            // as growth against the cap.
            if (! isset($this->absent[$mapKey]) && $this->atEntryCap()) {
                $this->evictColdest();
            }

            $attributes = new AttributeKnowledge;
            $attributes->recordFromModel($model, $allColumnsKnown);

            $this->entries[$mapKey] = new IdentityEntry(
                connection: ModelMetadata::connection($model),
                modelClass: $model::class,
                table: ModelMetadata::table($model),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $key,
                scopeFingerprint: $fingerprint,
                model: $model,
                attributes: $attributes,
                relations: new RelationKnowledge,
                state: LifecycleState::Exists,
                version: 1,
                lastTouched: ++$this->clock,
            );
            $this->entryKeysByClass[$model::class][$mapKey] = true;
        }

        unset($this->absent[$mapKey]);
        $this->ensureDiscoveredFor($model::class);
        $this->uniqueKeyIndex->index($this->entries[$mapKey], $mapKey);
    }

    public function markAllColumnsKnown(Model $model, ?string $fingerprint = null): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint ??= ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        if (isset($this->entries[$mapKey])) {
            $this->entries[$mapKey]->attributes->allColumnsKnown = true;
        }
    }

    /**
     * Snapshot a model's database-native full row for raw `DB::table()` read
     * serving. Only ever called from the read path with a model freshly
     * hydrated from a full-column SELECT, so getAttributes() holds the exact
     * pre-cast row the driver returned — the same bytes a raw query yields.
     *
     * No-op when the feature is disabled, so a full read costs nothing extra
     * unless raw serving is on.
     */
    public function captureRawRow(Model $model, ?string $fingerprint = null): void
    {
        if (! ($this->rawReadsEnabled ??= config('quantum-slipstream-drive.raw_reads.enabled') === true)) {
            return;
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint ??= ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        $entry = $this->entries[$mapKey] ?? null;

        if ($entry === null || $entry->state !== LifecycleState::Exists) {
            return;
        }

        $entry->rawRow = $model->getAttributes();
        $entry->rawRowVersion = $entry->version;

        $physicalKey = $this->makePhysicalKey(
            ModelMetadata::connection($model),
            ModelMetadata::table($model),
            $model->getKeyName(),
            $key,
        );
        $this->rawRowIndex[$physicalKey][$mapKey] = true;
    }

    /**
     * Resolve a database-native full row for a raw single-key read, or null
     * when no fresh snapshot covers it (the caller then falls through to SQL).
     *
     * A snapshot is served only while its entry is live and unchanged since
     * capture (version match), so a raw read never returns a row the database
     * would not.
     *
     * @return array<string, mixed>|null
     */
    public function findRawRow(
        string $connection,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
    ): ?array {
        if ($this->disabled) {
            return null;
        }

        $physicalKey = $this->makePhysicalKey($connection, $table, $primaryKeyName, $primaryKeyValue);

        foreach (array_keys($this->rawRowIndex[$physicalKey] ?? []) as $mapKey) {
            $entry = $this->entries[$mapKey] ?? null;

            if ($entry === null) {
                unset($this->rawRowIndex[$physicalKey][$mapKey]);

                continue;
            }

            if (
                $entry->state === LifecycleState::Exists
                && $entry->rawRow !== null
                && $entry->rawRowVersion === $entry->version
            ) {
                $entry->lastTouched = ++$this->clock;

                return $entry->rawRow;
            }
        }

        return null;
    }

    private function makePhysicalKey(
        string $connection,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
    ): string {
        return "{$connection}|{$table}|{$primaryKeyName}|{$primaryKeyValue}";
    }

    public function afterSaved(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        $this->snapshotForJournal($mapKey);

        if (isset($this->entries[$mapKey])) {
            $entry = $this->entries[$mapKey];
            $entry->lastTouched = ++$this->clock;
            $entry->model = $model;
            $entry->state = LifecycleState::Exists;
            $entry->version++;
            $entry->attributes->mergeFromSaved($model);
            $entry->attributes->allColumnsKnown = true;
            $this->ensureDiscoveredFor($model::class);
            $this->uniqueKeyIndex->index($entry, $mapKey);
        } else {
            $this->remember($model, true);
        }

        unset($this->absent[$mapKey]);
    }

    public function afterDeleted(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            // deleted_at is now set, so fromModel() returns 'with-trashed' — but the
            // entry was stored before deletion with the non-trashed fingerprint.
            $mapKey = $this->makeKeyFromParts(
                ModelMetadata::connection($model),
                $model::class,
                ModelMetadata::table($model),
                $model->getKeyName(),
                $key,
                'soft-delete:default',
            );

            $this->snapshotForJournal($mapKey);

            if (isset($this->entries[$mapKey])) {
                $entry = $this->entries[$mapKey];
                $entry->state = LifecycleState::SoftDeleted;
                $entry->version++;
                $entry->attributes->mergeFromSaved($model);
            }

            // The model is definitively soft-deleted within this process; record absence
            // immediately so subsequent default-scope finds skip SQL entirely.
            $this->absent[$mapKey] = ++$this->clock;
            $this->absentKeysByClass[$model::class][$mapKey] = true;
        } else {
            $fingerprint = ScopeFingerprinter::fromModel($model);
            $mapKey = $this->makeKey($model, $key, $fingerprint);

            $this->snapshotForJournal($mapKey);

            if (isset($this->entries[$mapKey])) {
                $this->entries[$mapKey]->state = LifecycleState::Deleted;
                $this->entries[$mapKey]->version++;
            }
        }
    }

    public function afterForceDeleted(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        // deleted_at is set by the time this fires; use the pre-deletion fingerprint
        $mapKey = $this->makeKeyFromParts(
            ModelMetadata::connection($model),
            $model::class,
            ModelMetadata::table($model),
            $model->getKeyName(),
            $key,
            'soft-delete:default',
        );

        if (isset($this->entries[$mapKey])) {
            $this->entries[$mapKey]->state = LifecycleState::Deleted;
            $this->entries[$mapKey]->version++;
        }

        // afterDeleted() fires first (during forceDelete) and records absence, but
        // force-delete intentionally does not record absence — next find must hit SQL.
        unset($this->absent[$mapKey]);
    }

    public function findEntry(Model $model): ?IdentityEntry
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        return $this->touchEntry($this->entries[$mapKey] ?? null);
    }

    public function find(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): ?IdentityEntry {
        $mapKey = $this->makeKeyFromParts(
            $connection,
            $modelClass,
            $table,
            $primaryKeyName,
            $primaryKeyValue,
            $fingerprint,
        );

        return $this->touchEntry($this->entries[$mapKey] ?? null);
    }

    public function isAbsent(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): bool {
        $mapKey = $this->makeKeyFromParts(
            $connection,
            $modelClass,
            $table,
            $primaryKeyName,
            $primaryKeyValue,
            $fingerprint,
        );

        if (! isset($this->absent[$mapKey])) {
            return false;
        }

        $this->absent[$mapKey] = ++$this->clock;

        return true;
    }

    public function recordAbsent(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): void {
        $mapKey = $this->makeKeyFromParts(
            $connection,
            $modelClass,
            $table,
            $primaryKeyName,
            $primaryKeyValue,
            $fingerprint,
        );

        if (! isset($this->absent[$mapKey]) && ! isset($this->entries[$mapKey]) && $this->atEntryCap()) {
            $this->evictColdest();
        }

        $this->snapshotForJournal($mapKey);

        $this->absent[$mapKey] = ++$this->clock;
        $this->absentKeysByClass[$modelClass][$mapKey] = true;
    }

    public function forget(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        unset($this->entries[$mapKey], $this->absent[$mapKey]);
    }

    public function flush(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            $this->entries = [];
            $this->absent = [];
            $this->clock = 0;
            $this->entryKeysByClass = [];
            $this->absentKeysByClass = [];
            $this->rawRowIndex = [];
            $this->observabilityEnabled = null;
            $this->rawReadsEnabled = null;
            $this->uniqueKeyIndex->flush();
            resolve(SchemaDiscovery::class)->flush();
            ScopeFingerprinter::flush();
            ModelMetadata::flush();

            return;
        }

        foreach (array_keys($this->entryKeysByClass[$modelClass] ?? []) as $key) {
            unset($this->entries[$key]);
        }
        unset($this->entryKeysByClass[$modelClass]);

        foreach (array_keys($this->absentKeysByClass[$modelClass] ?? []) as $key) {
            unset($this->absent[$key]);
        }
        unset($this->absentKeysByClass[$modelClass]);

        $this->uniqueKeyIndex->flush($modelClass);
    }

    /**
     * Partition a bounded key set into memory hits, absent keys, and unknown keys.
     *
     * @param  list<int|string>  $keys
     * @param  list<string>  $columns
     * @return array{0: array<int|string, IdentityEntry>, 1: list<int|string>, 2: list<int|string>}
     */
    public function partitionKeySet(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        array $keys,
        string $fingerprint,
        array $columns,
    ): array {
        $hits = [];
        $absentKeys = [];
        $unknownKeys = [];

        foreach ($keys as $key) {
            $entry = $this->find($connection, $modelClass, $table, $primaryKeyName, $key, $fingerprint);

            if ($entry instanceof IdentityEntry) {
                if ($entry->state === LifecycleState::Exists && $entry->attributes->satisfies($columns)) {
                    $hits[$key] = $entry;
                } elseif ($entry->state === LifecycleState::Exists) {
                    $unknownKeys[] = $key;
                } elseif ($entry->state === LifecycleState::SoftDeleted) {
                    // We know the record is soft-deleted; default scope won't return it.
                    $absentKeys[] = $key;
                } else {
                    // Deleted (force-deleted): absence is not recorded by design — fall through to SQL.
                    $unknownKeys[] = $key;
                }
            } elseif ($this->isAbsent($connection, $modelClass, $table, $primaryKeyName, $key, $fingerprint)) {
                $absentKeys[] = $key;
            } else {
                $unknownKeys[] = $key;
            }
        }

        return [$hits, $absentKeys, $unknownKeys];
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function disabled(Closure $callback): mixed
    {
        $previous = $this->disabled;
        $this->disabled = true;

        try {
            return $callback();
        } finally {
            $this->disabled = $previous;
        }
    }

    /** @return list<Explanation> */
    public function explain(Closure $callback): array
    {
        $previous = $this->capturing;
        $previousCaptured = $this->captured;
        $this->capturing = true;
        $this->captured = [];

        $result = [];

        try {
            $callback();
            $result = $this->captured;
        } finally {
            $this->capturing = $previous;
            $this->captured = $previousCaptured;
        }

        return $result;
    }

    public function capture(Explanation $explanation): void
    {
        if ($this->capturing) {
            $this->captured[] = $explanation;
        }

        if (! ($this->observabilityEnabled ??= config('quantum-slipstream-drive.observability.enabled') === true)) {
            return;
        }

        $this->streamDecision($explanation);
    }

    private function streamDecision(Explanation $explanation): void
    {
        Event::dispatch(new QueryDecided($explanation));

        $channel = config('quantum-slipstream-drive.observability.channel');
        $level = config('quantum-slipstream-drive.observability.level', 'info');

        $logger = is_string($channel) && $channel !== ''
            ? Log::channel($channel)
            : Log::driver();

        $logger->log(
            is_string($level) ? $level : 'info',
            (string) $explanation,
            ['context' => $explanation->toArray()],
        );
    }

    public function isCapturing(): bool
    {
        return $this->capturing;
    }

    /**
     * Look up an identity entry by unique-key equality values.
     *
     * Returns null if the entry is not indexed, the index is stale, or the
     * stored attributes no longer match the queried values.
     *
     * @param  array<string, mixed>  $equalityValues  column → value (order-independent)
     */
    public function findByUniqueKey(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $equalityValues,
    ): ?IdentityEntry {
        ksort($equalityValues);
        $uniqueFp = $this->uniqueKeyIndex->makeFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        $mapKey = $this->uniqueKeyIndex->findMapKey($uniqueFp);
        if ($mapKey === null) {
            return null;
        }

        $entry = $this->entries[$mapKey] ?? null;
        if ($entry === null) {
            $this->uniqueKeyIndex->evict($uniqueFp);

            return null;
        }

        foreach ($equalityValues as $column => $value) {
            $fact = $entry->attributes->get($column);
            if (! $fact instanceof AttributeFact) {
                // The index points at an entry that no longer carries the indexed
                // column. Evict so the next lookup falls through to SQL and
                // re-indexes, instead of missing against this stale entry forever.
                $this->uniqueKeyIndex->evict($uniqueFp);

                return null;
            }
            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            if ($fact->originalValue != $value) {
                $this->uniqueKeyIndex->evict($uniqueFp);

                return null;
            }
        }

        return $this->touchEntry($entry);
    }

    /**
     * @param  array<string, mixed>  $equalityValues  column → value (order-independent)
     */
    public function isAbsentByUniqueKey(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $equalityValues,
    ): bool {
        ksort($equalityValues);
        $uniqueFp = $this->uniqueKeyIndex->makeFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        return $this->uniqueKeyIndex->isAbsent($uniqueFp);
    }

    /**
     * @param  array<string, mixed>  $equalityValues  column → value (order-independent)
     */
    public function recordAbsentByUniqueKey(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $equalityValues,
    ): void {
        ksort($equalityValues);
        $uniqueFp = $this->uniqueKeyIndex->makeFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        $this->uniqueKeyIndex->recordAbsent($uniqueFp);
    }

    /**
     * Drop recorded unique-key absence for a model class. A mass update can make a
     * previously-absent unique value present (a restore, or a write to the indexed
     * column), so its stale absence must not survive to answer a later lookup.
     */
    public function forgetUniqueAbsence(string $modelClass): void
    {
        $this->uniqueKeyIndex->forgetAbsent($modelClass);
    }

    /**
     * Whether $values would resurrect a soft-deleted row — i.e. it sets the
     * model's soft-delete column back to null. Used to decide whether a mass
     * update must evict an already-deleted cached entry rather than skip it.
     *
     * @param  array<string, mixed>  $values
     */
    private function updateResurrects(Model $model, array $values): bool
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }

        $column = $model->getDeletedAtColumn();

        return array_key_exists($column, $values) && $values[$column] === null;
    }

    /**
     * Apply a mass-update to mapped entries for $modelClass without going to SQL.
     *
     * Entries whose predicate evaluates to Match have $values applied to their
     * attribute facts and underlying model object.  Unknown entries are evicted
     * (we cannot guarantee their post-update state).  Rejected entries are left
     * untouched.
     *
     * @param  array<string, mixed>  $values
     */
    public function applyMassUpdate(
        string $modelClass,
        PredicateNode $predicate,
        array $values,
        PredicateEvaluator $evaluator,
    ): bool {
        if ($this->disabled) {
            return false;
        }

        $this->ensureDiscoveredFor($modelClass);

        $keysToEvict = [];

        foreach ($this->entries as $mapKey => $entry) {
            if ($entry->modelClass !== $modelClass) {
                continue;
            }

            $evalResult = $evaluator->evaluate($entry->attributes, $predicate);

            if ($entry->state !== LifecycleState::Exists) {
                // A restore (an update clearing the soft-delete column) resurrects
                // a non-live cached entry — a transition that cannot be modeled in
                // place, and which would otherwise leave a stale row and a stale
                // unique-index pointer behind. Evict it (unless it provably cannot
                // match) so the next read re-fetches the true, live row. Any other
                // update on an already-deleted entry is a no-op for its lifecycle
                // and is left untouched.
                if ($evalResult !== EvaluationResult::Reject && $this->updateResurrects($entry->model, $values)) {
                    $this->snapshotForJournal($mapKey);
                    $keysToEvict[] = $mapKey;
                }

                continue;
            }

            if ($evalResult === EvaluationResult::Match) {
                $this->snapshotForJournal($mapKey);
                $rawAttrs = $entry->model->getAttributes();

                foreach ($values as $col => $val) {
                    $rawAttrs[$col] = $val;

                    $existing = $entry->attributes->get($col);

                    if ($existing instanceof AttributeFact) {
                        $existing->originalValue = $val;
                        $existing->currentValue = $val;
                        $existing->isDirty = false;
                        $existing->confidence = FactConfidence::Certain;
                        $existing->source = FactSource::MassWrite;
                    } else {
                        $entry->attributes->set($col, new AttributeFact(
                            column: $col,
                            originalValue: $val,
                            currentValue: $val,
                            isDirty: false,
                            confidence: FactConfidence::Certain,
                            source: FactSource::MassWrite,
                        ));
                    }
                }

                $entry->model->setRawAttributes($rawAttrs, true);
                $entry->version++;
                $this->uniqueKeyIndex->index($entry, $mapKey);
            } elseif ($evalResult === EvaluationResult::Unknown) {
                $this->snapshotForJournal($mapKey);
                $keysToEvict[] = $mapKey;
            }
        }

        foreach ($keysToEvict as $key) {
            unset($this->entries[$key]);
        }

        return $keysToEvict !== [];
    }

    /**
     * Apply a mass-delete to mapped entries for $modelClass without going to SQL.
     *
     * Entries that Match the predicate are marked Deleted (or SoftDeleted with
     * absence tracking for soft-delete models).  Unknown entries are evicted.
     * Rejected entries are left untouched.
     */
    public function applyMassDelete(
        string $modelClass,
        PredicateNode $predicate,
        PredicateEvaluator $evaluator,
        bool $softDeletes,
    ): bool {
        if ($this->disabled) {
            return false;
        }

        $keysToEvict = [];

        foreach ($this->entries as $mapKey => $entry) {
            if ($entry->modelClass !== $modelClass) {
                continue;
            }
            if ($entry->state !== LifecycleState::Exists) {
                continue;
            }
            $evalResult = $evaluator->evaluate($entry->attributes, $predicate);

            if ($evalResult === EvaluationResult::Match) {
                $this->snapshotForJournal($mapKey);

                if ($softDeletes) {
                    $entry->state = LifecycleState::SoftDeleted;
                    $this->absent[$mapKey] = ++$this->clock;
                    $this->absentKeysByClass[$modelClass][$mapKey] = true;
                } else {
                    $entry->state = LifecycleState::Deleted;
                }

                $entry->version++;
            } elseif ($evalResult === EvaluationResult::Unknown) {
                $this->snapshotForJournal($mapKey);
                $keysToEvict[] = $mapKey;
            }
        }

        foreach ($keysToEvict as $key) {
            unset($this->entries[$key]);
        }

        return $keysToEvict !== [];
    }

    /** @return array<string, mixed> */
    public function debugStats(): array
    {
        return array_merge([
            'entries' => count($this->entries),
            'absent' => count($this->absent),
            'disabled' => $this->disabled,
        ], $this->uniqueKeyIndex->debugStats());
    }

    /** @return list<list<string>> */
    public function uniqueIndexesForModelClass(string $modelClass): array
    {
        $this->ensureDiscoveredFor($modelClass);

        return $this->uniqueKeyIndex->uniqueIndexesForModelClass($modelClass);
    }

    private function ensureDiscoveredFor(string $modelClass): void
    {
        if ($this->uniqueKeyIndex->hasDiscovered($modelClass)) {
            return;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->uniqueKeyIndex->markDiscovered($modelClass);

            return;
        }

        foreach (resolve(SchemaDiscovery::class)->uniqueIndexesFor($modelClass) as $columns) {
            $this->uniqueKeyIndex->register($modelClass, $columns);
        }

        $this->uniqueKeyIndex->markDiscovered($modelClass);
    }

    /**
     * Record the pre-mutation state of $entryKey in the journal, if a transaction is active
     * on the entry's connection. Idempotent within a single transaction level — only the
     * first snapshot per key is kept.
     */
    private function snapshotForJournal(string $entryKey): void
    {
        if (! $this->journal instanceof TransactionJournal) {
            return;
        }

        // entryKey format: "{connection}|{modelClass}|..."
        $parts = explode('|', $entryKey, 3);
        if (count($parts) < 2) {
            return;
        }
        [$connection, $modelClass] = $parts;

        if (! $this->journal->isActive($connection)) {
            return;
        }

        $existing = $this->entries[$entryKey] ?? null;
        $wasAbsent = isset($this->absent[$entryKey]);

        $this->journal->snapshot($connection, new JournalEntry(
            entryKey: $entryKey,
            modelClass: $modelClass,
            before: $existing === null ? null : clone $existing,
            wasAbsent: $wasAbsent,
            modelOriginal: $existing?->model->getRawOriginal(),
        ));
    }

    /**
     * Apply rolled-back snapshots to the identity map.
     *
     * For each journal entry: restore the absent flag, then either remove the entry
     * (if it didn't exist before the transaction) or replace it with the snapshot
     * and reset the underlying model's raw attributes to their pre-transaction state.
     * Restored entries are re-indexed in the unique-key index so subsequent
     * findByUniqueKey() lookups see the restored values; stale in-tx fingerprints
     * are evicted lazily on lookup.
     *
     * @param  list<JournalEntry>  $entries
     */
    public function restoreFromJournal(array $entries): void
    {
        foreach ($entries as $journalEntry) {
            if ($journalEntry->wasAbsent) {
                $this->absent[$journalEntry->entryKey] = ++$this->clock;
                $this->absentKeysByClass[$journalEntry->modelClass][$journalEntry->entryKey] = true;
            } else {
                unset($this->absent[$journalEntry->entryKey]);
            }

            if ($journalEntry->before === null) {
                unset($this->entries[$journalEntry->entryKey]);

                continue;
            }

            $this->entries[$journalEntry->entryKey] = $journalEntry->before;
            $this->entryKeysByClass[$journalEntry->modelClass][$journalEntry->entryKey] = true;

            if ($journalEntry->modelOriginal !== null) {
                $journalEntry->before->model->setRawAttributes(
                    $journalEntry->modelOriginal,
                    true,
                );
            }

            $this->uniqueKeyIndex->index($journalEntry->before, $journalEntry->entryKey);
        }
    }

    private function makeKey(Model $model, int|string $primaryKeyValue, string $fingerprint): string
    {
        return $this->makeKeyFromParts(
            ModelMetadata::connection($model),
            $model::class,
            ModelMetadata::table($model),
            $model->getKeyName(),
            $primaryKeyValue,
            $fingerprint,
        );
    }

    private function makeKeyFromParts(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): string {
        return "{$connection}|{$modelClass}|{$table}|{$primaryKeyName}|{$primaryKeyValue}|{$fingerprint}";
    }
}
