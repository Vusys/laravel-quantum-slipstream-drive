<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Driver\ColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\DriverSemanticsResolver;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Knowledge\ColumnBackfiller;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Store\JournalEntry;
use Vusys\QueryRicerExtreme\Store\TransactionJournal;

class QueryRicerExtremeServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/query-ricer-extreme.php', 'query-ricer-extreme');

        $this->app->singleton(TransactionJournal::class);
        $this->app->singleton(IdentityMapStore::class, fn ($app): IdentityMapStore => new IdentityMapStore(
            $app->make(TransactionJournal::class),
            $this->capValue('query-ricer-extreme.store_caps.max_entries', 100000),
            $this->capValue('query-ricer-extreme.store_caps.max_unique_keys', 100000),
        ));
        $this->app->singleton(CoverageRegistry::class, fn (): CoverageRegistry => new CoverageRegistry(
            $this->capValue('query-ricer-extreme.store_caps.max_coverage_entries', 50000),
        ));
        $this->app->singleton(SchemaDiscovery::class);
        $this->app->singleton(DriverSemanticsResolver::class);
        $this->app->singleton(ColumnSemanticsResolver::class, fn ($app) => $app->make(SchemaDiscovery::class));
        $this->app->singleton(ColumnBackfiller::class);
        $this->app->singleton(IdentityGraph::class, fn (): IdentityGraph => new IdentityGraph(
            maxEdges: $this->capValue('query-ricer-extreme.relation_graph.max_edges', 50000),
            maxCoverage: $this->capValue('query-ricer-extreme.relation_graph.max_coverage_entries', 5000),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/query-ricer-extreme.php' => config_path('query-ricer-extreme.php'),
            ], 'query-ricer-extreme-config');
        }

        $this->registerLifecycleHooks();
    }

    private function registerLifecycleHooks(): void
    {
        if ($this->app->bound(HttpKernel::class)) {
            $this->app->terminating(function (): void {
                $this->flushAll();
            });
        }

        Event::listen(JobProcessing::class, function (): void {
            $this->flushAll();
        });

        Event::listen(JobProcessed::class, function (): void {
            $this->flushAll();
        });

        Event::listen(JobFailed::class, function (): void {
            $this->flushAll();
        });

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            $this->app->make(TransactionJournal::class)->begin($event->connectionName);
        });

        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $this->app->make(TransactionJournal::class)->commit($event->connectionName);
        });

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $journal = $this->app->make(TransactionJournal::class);
            $store = $this->app->make(IdentityMapStore::class);
            $registry = $this->app->make(CoverageRegistry::class);
            $graph = $this->app->make(IdentityGraph::class);

            $wasActive = $journal->isActive($event->connectionName);
            $entries = $journal->rollback($event->connectionName);

            if (! $wasActive) {
                // Rollback fired without a tracked begin (e.g. package booted mid-transaction).
                // Safe fallback: wipe everything.
                $store->flush();
                $registry->flush();
                $graph->flush();

                return;
            }

            $store->restoreFromJournal($entries);

            $touchedClasses = array_unique(array_map(
                static fn (JournalEntry $e): string => $e->modelClass,
                $entries,
            ));

            foreach (array_filter($touchedClasses) as $class) {
                $registry->flushModelClass($class);
                $graph->invalidateModelClass($class);
            }
        });
    }

    /**
     * Resolve a memory-growth cap (store or relation-graph) from config. A
     * positive integer enables the cap; a literal 0 (or negative) disables it;
     * anything malformed (a typo'd env string, null) falls back to $default so a
     * mistake can never silently remove the guard.
     */
    private function capValue(string $configKey, int $default): ?int
    {
        $value = config($configKey);

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return $default;
    }

    private function flushAll(): void
    {
        $this->app->make(IdentityMapStore::class)->flush();
        $this->app->make(CoverageRegistry::class)->flush();
        $this->app->make(TransactionJournal::class)->flush();
        $this->app->make(SchemaDiscovery::class)->flush();
        $this->app->make(IdentityGraph::class)->flush();
    }
}
