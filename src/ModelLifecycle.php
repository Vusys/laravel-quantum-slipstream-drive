<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\Graph\ModelIdentity;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;

/**
 * What the identity map does on each Eloquent model event. Shared by the
 * {@see HasIdentityMap} trait's per-class listeners and the all-models mode's
 * wildcard listeners ({@see AllModels}), so both keep the store in step the
 * same way.
 *
 * @internal
 */
final class ModelLifecycle
{
    public static function retrieved(Model $model): void
    {
        resolve(IdentityMapStore::class)->remember($model);
    }

    public static function saved(Model $model): void
    {
        resolve(IdentityMapStore::class)->afterSaved($model);
        $graph = resolve(IdentityGraph::class);

        if ($model->wasRecentlyCreated) {
            resolve(CoverageRegistry::class)->flushModelClass($model::class);
            $graph->invalidateModelClass($model::class);
        } else {
            $changedColumns = array_keys($model->getChanges());

            if ($changedColumns !== []) {
                resolve(CoverageRegistry::class)->flushByColumns($model::class, $changedColumns);
            }

            $identity = ModelIdentity::fromModel($model);
            if ($identity instanceof ModelIdentity) {
                $graph->invalidateModel($identity);
            }
        }
    }

    public static function deleted(Model $model): void
    {
        resolve(IdentityMapStore::class)->afterDeleted($model);
        resolve(CoverageRegistry::class)->flushModelClass($model::class);
        resolve(IdentityGraph::class)->invalidateModelClass($model::class);
    }

    public static function restored(Model $model): void
    {
        resolve(IdentityMapStore::class)->afterSaved($model);
        resolve(CoverageRegistry::class)->flushModelClass($model::class);
        resolve(IdentityGraph::class)->invalidateModelClass($model::class);
    }

    public static function forceDeleted(Model $model): void
    {
        resolve(IdentityMapStore::class)->afterForceDeleted($model);
    }
}
