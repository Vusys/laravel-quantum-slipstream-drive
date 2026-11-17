<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnSemantics;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnSemanticsResolver;
use Vusys\QuantumSlipstreamDrive\Driver\ColumnType;
use Vusys\QuantumSlipstreamDrive\Driver\StringComparisonMode;

final class SchemaDiscovery implements ColumnSemanticsResolver
{
    /** @var array<string, list<list<string>>> */
    private array $uniqueIndexCache = [];

    /** @var array<string, array<string, ColumnSemantics>> */
    private array $columnSemanticsCache = [];

    /** @var array<string, list<array{string, string}>> */
    private array $foreignKeyCache = [];

    /** @var array<string, array<string, true>> */
    private array $deleteReachCache = [];

    /** @var array<string, array<string, true>> */
    private array $truncateReachCache = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<list<string>>
     */
    public function uniqueIndexesFor(string $modelClass): array
    {
        if (config('quantum-slipstream-drive.schema_discovery.enabled', true) === false) {
            return [];
        }

        /** @var Model $instance */
        $instance = new $modelClass;
        $connectionName = $instance->getConnection()->getName();
        $table = $instance->getTable();
        $cacheKey = $this->cacheKey($modelClass, $connectionName, $table);

        if (isset($this->uniqueIndexCache[$cacheKey])) {
            return $this->uniqueIndexCache[$cacheKey];
        }

        try {
            $indexes = $this->introspectIndexes($connectionName, $table);
        } catch (Throwable) {
            return $this->uniqueIndexCache[$cacheKey] = [];
        }

        $unique = [];

        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) !== true) {
                continue;
            }
            if (($index['primary'] ?? false) === true) {
                continue;
            }

            $columns = $index['columns'] ?? null;
            if (! is_array($columns)) {
                continue;
            }
            if ($columns === []) {
                continue;
            }
            if (! array_is_list($columns)) {
                continue;
            }

            foreach ($columns as $col) {
                if (! is_string($col)) {
                    continue 2;
                }
            }

            /** @var list<string> $columns */
            $unique[] = $columns;
        }

        return $this->uniqueIndexCache[$cacheKey] = $unique;
    }

    #[\Override]
    public function for(Model $model, string $column): ColumnSemantics
    {
        $semantics = $this->columnSemanticsForModel($model);

        return $semantics[$column] ?? ColumnSemantics::unknown();
    }

    /**
     * Tables whose deletes can change rows of $table through a foreign key's ON
     * DELETE action: a parent whose delete cascades to, or nulls or defaults,
     * this table's rows, and any table whose delete cascades to such a parent.
     * Empty when discovery is off or introspection fails.
     *
     * @return array<string, true>
     */
    public function tablesWhoseDeletesReach(?string $connectionName, string $table): array
    {
        $cacheKey = ($connectionName ?? '__default__').':'.$table;

        return $this->deleteReachCache[$cacheKey] ??= $this->collectReach($connectionName, $table, onlyCascades: false, visiting: []);
    }

    /**
     * Tables whose truncation can empty $table: any table it references, at
     * any depth, since PostgreSQL's TRUNCATE ... CASCADE follows every foreign
     * key whatever its ON DELETE action.
     *
     * @return array<string, true>
     */
    public function tablesWhoseTruncatesReach(?string $connectionName, string $table): array
    {
        $cacheKey = ($connectionName ?? '__default__').':'.$table;

        return $this->truncateReachCache[$cacheKey] ??= $this->collectReferenced($connectionName, $table, visiting: []);
    }

    public function flush(): void
    {
        $this->uniqueIndexCache = [];
        $this->columnSemanticsCache = [];
        $this->foreignKeyCache = [];
        $this->deleteReachCache = [];
        $this->truncateReachCache = [];
    }

    /**
     * @param  array<string, true>  $visiting
     * @return array<string, true>
     */
    private function collectReferenced(?string $connectionName, string $table, array $visiting): array
    {
        $visiting[$table] = true;
        $reach = [];

        foreach ($this->foreignKeysOf($connectionName, $table) as [$parent]) {
            $reach[$parent] = true;

            if (! isset($visiting[$parent])) {
                $reach += $this->collectReferenced($connectionName, $parent, $visiting);
            }
        }

        return $reach;
    }

    /**
     * @param  array<string, true>  $visiting
     * @return array<string, true>
     */
    private function collectReach(?string $connectionName, string $table, bool $onlyCascades, array $visiting): array
    {
        $visiting[$table] = true;
        $reach = [];

        foreach ($this->foreignKeysOf($connectionName, $table) as [$parent, $onDelete]) {
            $acts = $onlyCascades
                ? $onDelete === 'cascade'
                : in_array($onDelete, ['cascade', 'set null', 'set default'], true);

            if (! $acts) {
                continue;
            }

            $reach[$parent] = true;

            // Whatever deletes the parent's rows by cascade reaches this table too.
            if (! isset($visiting[$parent])) {
                $reach += $this->collectReach($connectionName, $parent, onlyCascades: true, visiting: $visiting);
            }
        }

        return $reach;
    }

    /**
     * The table's foreign keys as [referenced table, on-delete action].
     *
     * @return list<array{string, string}>
     */
    private function foreignKeysOf(?string $connectionName, string $table): array
    {
        if (config('quantum-slipstream-drive.schema_discovery.enabled', true) === false) {
            return [];
        }

        $cacheKey = ($connectionName ?? '__default__').':'.$table;

        if (isset($this->foreignKeyCache[$cacheKey])) {
            return $this->foreignKeyCache[$cacheKey];
        }

        try {
            $foreignKeys = $this->silently($connectionName, fn (): array => Schema::connection($connectionName)->getForeignKeys($table));
            $prefix = DB::connection($connectionName)->getTablePrefix();
        } catch (Throwable) {
            return $this->foreignKeyCache[$cacheKey] = [];
        }

        $parsed = [];

        foreach ($foreignKeys as $foreignKey) {
            $parent = $foreignKey['foreign_table'];
            $onDelete = $foreignKey['on_delete'];

            if ($onDelete === null) {
                continue;
            }

            if ($prefix !== '' && str_starts_with($parent, $prefix)) {
                $parent = substr($parent, strlen($prefix));
            }

            $parsed[] = [$parent, strtolower($onDelete)];
        }

        return $this->foreignKeyCache[$cacheKey] = $parsed;
    }

    /**
     * @return array<string, ColumnSemantics>
     */
    private function columnSemanticsForModel(Model $model): array
    {
        if (config('quantum-slipstream-drive.schema_discovery.enabled', true) === false) {
            return [];
        }

        $connection = $model->getConnection();
        $connectionName = $connection->getName();
        $table = $model->getTable();
        $cacheKey = $this->cacheKey($model::class, $connectionName, $table);

        if (isset($this->columnSemanticsCache[$cacheKey])) {
            return $this->columnSemanticsCache[$cacheKey];
        }

        $driver = $connection->getDriverName();

        try {
            $columns = $this->introspectColumns($connectionName, $table);
        } catch (Throwable) {
            return $this->columnSemanticsCache[$cacheKey] = [];
        }

        $configMode = $this->configuredStringMode($driver);
        $semantics = [];

        foreach ($columns as $col) {
            $name = $col['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            $typeName = is_string($col['type_name'] ?? null) ? strtolower($col['type_name']) : '';
            $collation = is_string($col['collation'] ?? null) ? $col['collation'] : null;

            $semantics[$name] = new ColumnSemantics(
                type: $this->mapType($typeName),
                collation: $collation,
                stringComparison: $this->resolveStringMode($driver, $typeName, $collation, $configMode),
            );
        }

        return $this->columnSemanticsCache[$cacheKey] = $semantics;
    }

    private function cacheKey(string $modelClass, ?string $connectionName, string $table): string
    {
        return $modelClass.'@'.($connectionName ?? '__default__').':'.$table;
    }

    /**
     * Run schema introspection without firing QueryExecuted events, so user-facing
     * query counters (DB::listen) are unaffected by our internal PRAGMA / SHOW INDEX
     * traffic.
     *
     * @return list<array<string, mixed>>
     */
    private function introspectIndexes(?string $connectionName, string $table): array
    {
        return $this->silently($connectionName, fn (): array => Schema::connection($connectionName)->getIndexes($table));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function introspectColumns(?string $connectionName, string $table): array
    {
        return $this->silently($connectionName, fn (): array => Schema::connection($connectionName)->getColumns($table));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function silently(?string $connectionName, \Closure $callback): mixed
    {
        $connection = DB::connection($connectionName);
        $dispatcher = $connection->getEventDispatcher();
        $loggingWasEnabled = $connection->logging();

        if ($dispatcher !== null) {
            $connection->unsetEventDispatcher();
        }

        if ($loggingWasEnabled) {
            $connection->disableQueryLog();
        }

        try {
            return $callback();
        } finally {
            if ($loggingWasEnabled) {
                $connection->enableQueryLog();
            }

            if ($dispatcher !== null) {
                $connection->setEventDispatcher($dispatcher);
            }
        }
    }

    private function mapType(string $typeName): ColumnType
    {
        return match (true) {
            $typeName === '' => ColumnType::Unknown,
            in_array($typeName, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int2', 'int4', 'int8'], true) => ColumnType::Integer,
            in_array($typeName, ['float', 'double', 'real', 'numeric', 'decimal'], true) => ColumnType::Float,
            in_array($typeName, ['bool', 'boolean'], true) => ColumnType::Boolean,
            $typeName === 'uuid' => ColumnType::Uuid,
            $typeName === 'date' => ColumnType::Date,
            in_array($typeName, ['datetime', 'timestamp', 'timestamptz'], true) => ColumnType::DateTime,
            in_array($typeName, ['json', 'jsonb'], true) => ColumnType::Json,
            in_array($typeName, ['blob', 'bytea', 'binary', 'varbinary'], true) => ColumnType::Binary,
            $this->isStringType($typeName) => ColumnType::String,
            default => ColumnType::Unknown,
        };
    }

    private function isStringType(string $typeName): bool
    {
        return str_contains($typeName, 'char')
            || str_contains($typeName, 'text')
            || $typeName === 'citext'
            || $typeName === 'enum';
    }

    /**
     * @return 'database_collation'|'php_strict'|'conservative_unknown'
     */
    private function configuredStringMode(string $driver): string
    {
        $value = config('quantum-slipstream-drive.database_semantics.'.$driver.'.string_comparisons', 'conservative_unknown');

        return match ($value) {
            'database_collation', 'php_strict', 'conservative_unknown' => $value,
            default => 'conservative_unknown',
        };
    }

    /**
     * @param  'database_collation'|'php_strict'|'conservative_unknown'  $configMode
     */
    private function resolveStringMode(string $driver, string $typeName, ?string $collation, string $configMode): StringComparisonMode
    {
        if (! $this->isStringType($typeName) && $typeName !== '') {
            return StringComparisonMode::Unknown;
        }

        if ($configMode === 'php_strict') {
            return StringComparisonMode::CaseSensitive;
        }

        if ($configMode === 'conservative_unknown') {
            return StringComparisonMode::Unknown;
        }

        // database_collation
        if ($typeName === 'citext') {
            return StringComparisonMode::CaseInsensitive;
        }

        if (is_string($collation) && $collation !== '') {
            return $this->modeFromCollation($collation);
        }

        return match ($driver) {
            'sqlite', 'pgsql' => StringComparisonMode::CaseSensitive,
            default => StringComparisonMode::Unknown,
        };
    }

    private function modeFromCollation(string $collation): StringComparisonMode
    {
        $lower = strtolower($collation);

        if (str_ends_with($lower, '_bin') || str_contains($lower, '_cs')) {
            return StringComparisonMode::CaseSensitive;
        }

        if (str_contains($lower, '_ci') || str_contains($lower, '_ai')) {
            return StringComparisonMode::CaseInsensitive;
        }

        return StringComparisonMode::Unknown;
    }
}
