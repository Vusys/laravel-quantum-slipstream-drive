<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Enums\PlanType;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\Graph\IdentityGraph;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;

/**
 * Correctness guard for raw query-builder writes.
 *
 * `DB::table('users')->update(...)` / `->delete()` / `->insert(...)` bypass the
 * Eloquent {@see IdentityMapBuilder} entirely, mutating rows the store still
 * believes are cached. A connection-level hook watches executed write
 * statements and, when one targets a table backing an identity-mapped model,
 * conservatively flushes that model's cached state so the next read is fresh.
 *
 * Writes issued through the modeled Eloquent path wrap themselves in
 * {@see withoutInterception()} so their SQL is ignored here — the model
 * lifecycle and mass-write paths already keep the cache consistent, precisely.
 */
final class RawWriteInterceptor
{
    private static int $suppressionDepth = 0;

    /**
     * Model registration is process-stable (a class always backs the same
     * table), and model boot fires only once per process while the container is
     * rebuilt per request/test — so the registry is static to survive rebuilds.
     *
     * @var array<class-string<Model>, true>
     */
    private static array $registered = [];

    /** @var array<string, list<class-string<Model>>>|null table name => model classes; rebuilt lazily */
    private static ?array $tableMap = null;

    public function __construct(
        private readonly IdentityMapStore $store,
        private readonly CoverageRegistry $registry,
        private readonly IdentityGraph $graph,
    ) {}

    /**
     * Run $callback with the connection-level write hook suppressed, so writes
     * that the Eloquent path already models don't get conservatively flushed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutInterception(callable $callback): mixed
    {
        self::$suppressionDepth++;

        try {
            return $callback();
        } finally {
            self::$suppressionDepth--;
        }
    }

    /**
     * Reset suppression to zero at a request/job boundary. `withoutInterception()`
     * already balances itself with try/finally, but a fatal error or `exit()`
     * mid-write could skip the decrement; on a long-lived worker (Octane, queue)
     * that leaked depth would silently suppress the next request's invalidation.
     * Called from the package's boundary flush so each request starts clean.
     */
    public static function resetSuppression(): void
    {
        self::$suppressionDepth = 0;
    }

    /** @param class-string<Model> $modelClass */
    public function registerModel(string $modelClass): void
    {
        self::$registered[$modelClass] = true;
        self::$tableMap = null;
    }

    public function handle(QueryExecuted $event): void
    {
        if (self::$suppressionDepth > 0) {
            return;
        }

        $parsed = $this->parseWrite($event->sql);

        if ($parsed === null) {
            return;
        }

        [$operation, $rawTable] = $parsed;
        $table = $this->stripPrefix($rawTable, $event->connection->getTablePrefix());

        foreach ($this->classesForTable($table) as $class) {
            $this->store->flush($class);
            $this->registry->flushModelClass($class);
            $this->graph->invalidateModelClass($class);

            $this->store->capture(new Explanation(
                type: PlanType::RawWriteInvalidation,
                modelClass: $class,
                reason: "Raw builder {$operation} on `{$table}` bypassed Eloquent; conservatively flushed cached state for {$class}.",
                sqlExecuted: true,
            ));
        }
    }

    /** @return list<class-string<Model>> */
    private function classesForTable(string $table): array
    {
        self::$tableMap ??= $this->buildTableMap();

        return self::$tableMap[$table] ?? [];
    }

    /** @return array<string, list<class-string<Model>>> */
    private function buildTableMap(): array
    {
        $map = [];

        foreach (array_keys(self::$registered) as $class) {
            $map[(new $class)->getTable()][] = $class;
        }

        return $map;
    }

    /**
     * Detect a write statement and its target table. Returns [operation, table]
     * or null when the SQL is a read (or otherwise not a tracked write).
     *
     * @return array{string, string}|null
     */
    private function parseWrite(string $sql): ?array
    {
        // "insert ignore into" (MySQL) and "insert or ignore into" (SQLite) are
        // still inserts and must invalidate too.
        if (preg_match('/^\s*(insert(?:\s+or)?(?:\s+ignore)?\s+into|update|delete\s+from|truncate\s+table|truncate)\s+["`\[]?([a-zA-Z0-9_.]+)/i', $sql, $matches) !== 1) {
            return null;
        }

        $keyword = strtolower($matches[1]);
        $operation = match (true) {
            str_starts_with($keyword, 'insert') => 'insert',
            str_starts_with($keyword, 'update') => 'update',
            str_starts_with($keyword, 'delete') => 'delete',
            default => 'truncate',
        };

        $table = $matches[2];

        if (str_contains($table, '.')) {
            $parts = explode('.', $table);
            $table = end($parts);
        }

        return [$operation, $table];
    }

    private function stripPrefix(string $table, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }
}
