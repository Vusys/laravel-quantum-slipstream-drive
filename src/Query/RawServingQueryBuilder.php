<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Vusys\QuantumSlipstreamDrive\Enums\PlanType;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;

/**
 * Base query builder that serves raw `DB::table()` reads from the identity map.
 *
 * Installed per driver (only when `raw_reads.enabled`) so that a
 * `DB::table('users')->find(1)` — which bypasses Eloquent's
 * {@see IdentityMapBuilder} entirely — can be answered from a database-native
 * row snapshot the store captured on an earlier full-column SELECT, issuing
 * zero SQL and returning `stdClass` rows byte-identical to a bypassed query.
 *
 * Only single-key and bounded primary-key-set reads of the full row, against a
 * table backing an identity-mapped model, are served; anything else — extra
 * predicates, column projections, joins, an uncovered key — falls through to
 * SQL unchanged. Serving is suppressed while the builder is driving an Eloquent
 * query ({@see $ownedByEloquent}); that path already runs through
 * {@see IdentityMapBuilder}.
 */
class RawServingQueryBuilder extends QueryBuilder
{
    /**
     * True when this base builder backs an Eloquent query (set by
     * {@see IdentityMapBuilder::__construct()}). Eloquent reads are served by
     * the Eloquent builder, so raw serving stands down to avoid double-handling.
     */
    public bool $ownedByEloquent = false;

    /**
     * @param  list<string>|string  $columns
     * @return Collection<int, \stdClass>
     */
    #[\Override]
    public function get($columns = ['*'])
    {
        $served = $this->serveFromIdentityMap($columns);

        if ($served instanceof Collection) {
            return $served;
        }

        return parent::get($columns);
    }

    /**
     * @param  list<string>|string  $columns
     * @return Collection<int, \stdClass>|null
     */
    private function serveFromIdentityMap($columns): ?Collection
    {
        if ($this->ownedByEloquent) {
            return null;
        }

        if (config('quantum-slipstream-drive.raw_reads.enabled') !== true) {
            return null;
        }

        $connection = $this->connection;

        if (! $connection instanceof Connection) {
            return null;
        }

        if (! $this->isFullRowShape($columns)) {
            return null;
        }

        if (! is_string($this->from)) {
            return null;
        }

        $table = $this->unqualifiedTable($this->from, $connection->getTablePrefix());

        if ($table === null) {
            return null;
        }

        $classes = RawWriteInterceptor::modelClassesForTable($table);

        if ($classes === []) {
            return null;
        }

        $modelClass = $classes[0];
        $primaryKeyName = (new $modelClass)->getKeyName();
        $keys = $this->extractPrimaryKeyEquality($primaryKeyName);

        if ($keys === null) {
            return null;
        }

        $connectionName = $connection->getName();

        if (! is_string($connectionName)) {
            return null;
        }

        $store = resolve(IdentityMapStore::class);
        $rows = [];

        foreach ($keys as $key) {
            $row = $store->findRawRow($connectionName, $table, $primaryKeyName, $key);

            if ($row === null) {
                // Any uncovered key forces the whole read to SQL — a partial
                // in-memory answer could omit rows the query would return.
                return null;
            }

            $rows[$key] = (object) $row;
        }

        // Normalise key-set order to primary-key ascending: a whereIn without an
        // explicit ORDER BY has no defined SQL order, and pk-ascending is a
        // deterministic, portable ordering of the same set.
        ksort($rows);

        $store->capture(new Explanation(
            type: PlanType::ReturnRawRowFromMemory,
            modelClass: $modelClass,
            reason: count($keys) === 1 ? 'raw-db-read-single-key-hit' : 'raw-db-read-key-set-hit',
            sqlExecuted: false,
            memoryKeys: $keys,
        ));

        /** @var Collection<int, \stdClass> $collection */
        $collection = new Collection(array_values($rows));

        return $collection;
    }

    /**
     * Whether the read wants the full row (no column projection). Only `['*']`
     * qualifies — a specific column list is left to SQL so the served row can
     * never differ in shape from a bypassed query.
     *
     * @param  list<string>|string  $columns
     */
    private function isFullRowShape($columns): bool
    {
        if (
            ($this->joins !== null && $this->joins !== [])
            || ($this->groups !== null && $this->groups !== [])
            || ($this->havings !== null && $this->havings !== [])
            || ($this->unions !== null && $this->unions !== [])
            || $this->distinct !== false
            || $this->aggregate !== null
            || $this->lock !== null
            || $this->groupLimit !== null
            || ($this->offset !== null && $this->offset > 0)
            || $this->afterQueryCallbacks !== []
        ) {
            return false;
        }

        return $this->isStarColumns($this->columns) && $this->isStarColumns($columns);
    }

    private function isStarColumns(mixed $columns): bool
    {
        if ($columns === null) {
            return true;
        }

        if (is_string($columns)) {
            return $columns === '*';
        }

        return $columns === [] || $columns === ['*'];
    }

    /**
     * Extract the primary-key values a single-key or bounded key-set read
     * targets, or null when the WHERE clause is anything else (extra predicates,
     * non-key columns, OR, raw). No global scopes appear on raw builders, so the
     * key clause must be the sole condition.
     *
     * @return non-empty-list<int|string>|null
     */
    private function extractPrimaryKeyEquality(string $primaryKeyName): ?array
    {
        $wheres = $this->wheres;

        if (count($wheres) !== 1) {
            return null;
        }

        $where = $wheres[0];
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;
        $boolean = $where['boolean'] ?? null;

        if ($boolean !== 'and' || ! is_string($column) || ! $this->isPrimaryKeyColumn($column, $primaryKeyName)) {
            return null;
        }

        if ($type === 'Basic') {
            if (($where['operator'] ?? null) !== '=') {
                return null;
            }

            if (($this->limit !== null && $this->limit < 1)) {
                return null;
            }

            $value = $where['value'] ?? null;

            return (is_int($value) || is_string($value)) ? [$value] : null;
        }

        if ($type === 'In' || $type === 'InRaw') {
            $values = $where['values'] ?? null;

            if (! is_array($values) || $values === []) {
                return null;
            }

            // A LIMIT could truncate the set below the requested keys, changing
            // which rows come back; only serve an untruncated key-set read.
            if ($this->limit !== null && $this->limit < count($values)) {
                return null;
            }

            $keys = [];

            foreach ($values as $value) {
                if (! is_int($value) && ! is_string($value)) {
                    return null;
                }

                $keys[] = $value;
            }

            return $keys;
        }

        return null;
    }

    private function isPrimaryKeyColumn(string $column, string $primaryKeyName): bool
    {
        if ($column === $primaryKeyName) {
            return true;
        }

        $dot = strrpos($column, '.');

        return $dot !== false && substr($column, $dot + 1) === $primaryKeyName;
    }

    /**
     * Reduce a FROM expression to a bare, unqualified, unprefixed table name,
     * or null when it carries an alias / schema qualifier we can't safely map.
     */
    private function unqualifiedTable(string $from, string $prefix): ?string
    {
        if (preg_match('/^[A-Za-z0-9_.]+$/', $from) !== 1) {
            return null;
        }

        if ($prefix !== '' && str_starts_with($from, $prefix)) {
            $from = substr($from, strlen($prefix));
        }

        $dot = strrpos($from, '.');

        return $dot === false ? $from : substr($from, $dot + 1);
    }
}
