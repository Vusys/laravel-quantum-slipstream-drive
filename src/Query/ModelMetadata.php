<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-class memoization of Model::getTable().
 *
 * `$table` is a class-level convention in Eloquent — Eloquent itself only
 * exposes it as an instance property because of how models are constructed,
 * but its value is deterministic for the model class. The profile after #47
 * showed Model::getTable / Str::pluralStudly / Pluralizer::plural dominating
 * the hot path because models that don't set `protected $table` re-run
 * pluralization on every fresh instance, and the package creates many
 * fresh instances per request.
 *
 * Cache is keyed by `Model::class`. flush() is wired into
 * IdentityMapStore::flush() so the existing $store->flush() test pattern
 * also resets this cache.
 *
 * Connection name is NOT cached: codebases using a per-instance
 * `$this->setConnection(...)` to swap connections at runtime (tenancy,
 * test contexts, sharding) would see the first-observed connection cached
 * and returned for every subsequent call, breaking correctness. We keep
 * the API surface so callers don't churn, but it's a pass-through.
 */
final class ModelMetadata
{
    /** @var array<class-string<Model>, string> */
    private static array $tableCache = [];

    public static function table(Model $model): string
    {
        return self::$tableCache[$model::class] ??= $model->getTable();
    }

    /**
     * The model's key, or null when its row was read without the key column
     * (`value()`, `pluck()`, `select('name')`). `getKey()` itself throws there
     * under `Model::shouldBeStrict()`.
     */
    public static function key(Model $model): mixed
    {
        return array_key_exists($model->getKeyName(), $model->getAttributes()) ? $model->getKey() : null;
    }

    /**
     * A model returned on its own may lazy-load, as Laravel's hydrate() leaves a
     * one-row result under `Model::preventLazyLoading()`. A cached instance may
     * have come from a many-row read, which set the flag. Only ever lowered, so
     * serving from memory never makes a read throw that SQL wouldn't.
     *
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public static function servedAlone(Model $model): Model
    {
        $model->preventsLazyLoading = false;

        return $model;
    }

    public static function connection(Model $model): string
    {
        return $model->getConnection()->getName() ?? 'default';
    }

    public static function flush(): void
    {
        self::$tableCache = [];
    }
}
