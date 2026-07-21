<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Store;

use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeFact;

final class UniqueKeyIndex
{
    /** @var array<string, string> unique-key fingerprint → primary map key */
    private array $index = [];

    /** @var array<string, true> */
    private array $absent = [];

    /** @var array<string, list<list<string>>> indexes registered at runtime (e.g. by schema discovery) */
    private array $registered = [];

    /** @var array<string, true> classes that have completed runtime discovery */
    private array $discoveredClasses = [];

    public function __construct(
        /** @var int|null null disables the cap */
        private readonly ?int $maxKeys = null,
    ) {}

    public function index(IdentityEntry $entry, string $mapKey): void
    {
        foreach ($this->uniqueIndexesForModelClass($entry->modelClass) as $columns) {
            $values = [];

            foreach ($columns as $column) {
                $fact = $entry->attributes->get($column);

                if (! $fact instanceof AttributeFact) {
                    continue 2;
                }

                $values[$column] = $fact->originalValue;
            }

            ksort($values);
            $fp = $this->makeFingerprint($entry->connection, $entry->modelClass, $entry->table, $entry->scopeFingerprint, $values);

            if (! isset($this->index[$fp]) && $this->atCap()) {
                $this->flush();

                return;
            }

            $this->index[$fp] = $mapKey;
            unset($this->absent[$fp]);

            // A recorded absence is keyed by scope fingerprint, so a lookup under a
            // different scope (e.g. withTrashed) may have marked this same value
            // absent. Now that a row carries it, clear the absence under every scope
            // for this value — over-clearing only forgoes a cache hit, whereas a
            // lingering cross-scope absence would wrongly answer a later lookup.
            $this->forgetAbsentForValue($entry->connection, $entry->modelClass, $entry->table, $values);
        }
    }

    public function findMapKey(string $uniqueFingerprint): ?string
    {
        return $this->index[$uniqueFingerprint] ?? null;
    }

    public function evict(string $uniqueFingerprint): void
    {
        unset($this->index[$uniqueFingerprint]);
    }

    public function isAbsent(string $uniqueFingerprint): bool
    {
        return isset($this->absent[$uniqueFingerprint]);
    }

    /**
     * Drop the "no row has this unique value" markers for a model class while
     * keeping the positive index. A mass update can turn an absent unique value
     * present — a restore clearing deleted_at, or a write setting the column — so
     * a lingering absence would otherwise make a later unique lookup miss a now
     * live row and wrongly resolve to null.
     */
    public function forgetAbsent(string $modelClass): void
    {
        foreach (array_keys($this->absent) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->absent[$key]);
            }
        }
    }

    /**
     * Clear the "no row has this unique value" markers for one value across every
     * scope fingerprint. The fingerprint packs the scope between the table and the
     * value suffix, so match on the connection/class/table prefix and the value
     * suffix while ignoring the scope segment in between.
     *
     * @param  array<string, mixed>  $values  already ksorted
     */
    private function forgetAbsentForValue(string $connection, string $modelClass, string $table, array $values): void
    {
        $prefix = "{$connection}|{$modelClass}|{$table}|";
        $suffix = '|UQ:'.serialize($values);

        foreach (array_keys($this->absent) as $key) {
            if (str_starts_with($key, $prefix) && str_ends_with($key, $suffix)) {
                unset($this->absent[$key]);
            }
        }
    }

    public function recordAbsent(string $uniqueFingerprint): void
    {
        if (! isset($this->absent[$uniqueFingerprint]) && $this->atCap()) {
            $this->flush();

            return;
        }

        $this->absent[$uniqueFingerprint] = true;
    }

    private function atCap(): bool
    {
        return $this->maxKeys !== null
            && (count($this->index) + count($this->absent)) >= $this->maxKeys;
    }

    public function flush(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            $this->index = [];
            $this->absent = [];
            $this->registered = [];
            $this->discoveredClasses = [];

            return;
        }

        foreach (array_keys($this->index) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->index[$key]);
            }
        }

        foreach (array_keys($this->absent) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->absent[$key]);
            }
        }

        unset($this->registered[$modelClass], $this->discoveredClasses[$modelClass]);
    }

    /** @return list<list<string>> */
    public function uniqueIndexesForModelClass(string $modelClass): array
    {
        $rawConfig = config("quantum-slipstream-drive.models.{$modelClass}.unique");

        $result = [];
        $seen = [];

        if (is_array($rawConfig)) {
            foreach ($rawConfig as $indexEntry) {
                if (! is_array($indexEntry)) {
                    continue;
                }

                $columns = [];

                foreach ($indexEntry as $col) {
                    if (is_string($col)) {
                        $columns[] = $col;
                    }
                }

                if ($columns === []) {
                    continue;
                }

                $key = $this->columnSetKey($columns);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $result[] = $columns;
            }
        }

        foreach ($this->registered[$modelClass] ?? [] as $columns) {
            $key = $this->columnSetKey($columns);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $columns;
        }

        return $result;
    }

    /**
     * Register a discovered unique-index column set for the given model class.
     *
     * @param  list<string>  $columns
     */
    public function register(string $modelClass, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $existing = $this->registered[$modelClass] ?? [];
        $key = $this->columnSetKey($columns);

        foreach ($existing as $known) {
            if ($this->columnSetKey($known) === $key) {
                return;
            }
        }

        $existing[] = $columns;
        $this->registered[$modelClass] = $existing;
    }

    public function hasDiscovered(string $modelClass): bool
    {
        return isset($this->discoveredClasses[$modelClass]);
    }

    public function markDiscovered(string $modelClass): void
    {
        $this->discoveredClasses[$modelClass] = true;
    }

    /** @param list<string> $columns */
    private function columnSetKey(array $columns): string
    {
        $sorted = $columns;
        sort($sorted);

        return implode("\0", $sorted);
    }

    /** @param array<string, mixed> $values already ksorted */
    public function makeFingerprint(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $values,
    ): string {
        return "{$connection}|{$modelClass}|{$table}|{$fingerprint}|UQ:".serialize($values);
    }

    /** @return array{unique_index: int, unique_absent: int} */
    public function debugStats(): array
    {
        return [
            'unique_index' => count($this->index),
            'unique_absent' => count($this->absent),
        ];
    }
}
