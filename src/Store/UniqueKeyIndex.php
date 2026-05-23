<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;

final class UniqueKeyIndex
{
    /** @var array<string, string> unique-key fingerprint → primary map key */
    private array $index = [];

    /** @var array<string, true> */
    private array $absent = [];

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
            $this->index[$fp] = $mapKey;
            unset($this->absent[$fp]);
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

    public function recordAbsent(string $uniqueFingerprint): void
    {
        $this->absent[$uniqueFingerprint] = true;
    }

    public function flush(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            $this->index = [];
            $this->absent = [];

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
    }

    /** @return list<list<string>> */
    public function uniqueIndexesForModelClass(string $modelClass): array
    {
        $rawConfig = config("query-ricer-extreme.models.{$modelClass}.unique");

        if (! is_array($rawConfig)) {
            return [];
        }

        $result = [];

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

            if ($columns !== []) {
                $result[] = $columns;
            }
        }

        return $result;
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
