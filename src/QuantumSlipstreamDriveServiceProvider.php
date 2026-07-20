<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnSemanticsResolver;
use Vusys\QuantumSlipstreamDrive\Driver\DriverSemanticsResolver;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\Knowledge\ColumnBackfiller;
use Vusys\QuantumSlipstreamDrive\Query\RawWriteInterceptor;
use Vusys\QuantumSlipstreamDrive\Schema\SchemaDiscovery;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Store\JournalEntry;
use Vusys\QuantumSlipstreamDrive\Store\TransactionJournal;

class QuantumSlipstreamDriveServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/quantum-slipstream-drive.php', 'quantum-slipstream-drive');

        $this->app->singleton(TransactionJournal::class);
        $this->app->singleton(IdentityMapStore::class, fn ($app): IdentityMapStore => new IdentityMapStore(
            $app->make(TransactionJournal::class),
            $this->capValue('quantum-slipstream-drive.store_caps.max_entries', 100000),
            $this->capValue('quantum-slipstream-drive.store_caps.max_unique_keys', 100000),
        ));
        $this->app->singleton(CoverageRegistry::class, fn (): CoverageRegistry => new CoverageRegistry(
            $this->capValue('quantum-slipstream-drive.store_caps.max_coverage_entries', 50000),
        ));
        $this->app->singleton(SchemaDiscovery::class);
        $this->app->singleton(DriverSemanticsResolver::class);
        $this->app->singleton(ColumnSemanticsResolver::class, fn ($app) => $app->make(SchemaDiscovery::class));
        $this->app->singleton(ColumnBackfiller::class);
        $this->app->singleton(IdentityGraph::class, fn (): IdentityGraph => new IdentityGraph(
            maxEdges: $this->capValue('quantum-slipstream-drive.relation_graph.max_edges', 50000),
            maxCoverage: $this->capValue('quantum-slipstream-drive.relation_graph.max_coverage_entries', 5000),
        ));
        $this->app->singleton(RawWriteInterceptor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/quantum-slipstream-drive.php' => config_path('quantum-slipstream-drive.php'),
            ], 'quantum-slipstream-drive-config');
        }

        $this->registerLifecycleHooks();
        $this->registerRawWriteInterceptor();
    }

    /**
     * Watch executed write statements so raw query-builder writes
     * (`DB::table('users')->update(...)`) that bypass Eloquent conservatively
     * invalidate the cached state of any identity-mapped model on that table.
     */
    private function registerRawWriteInterceptor(): void
    {
        DB::listen(function (QueryExecuted $event): void {
            $this->app->make(RawWriteInterceptor::class)->handle($event);
        });
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
