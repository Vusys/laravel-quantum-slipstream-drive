<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

final class TransactionJournal
{
    /** @var list<array<string, JournalEntry>> stack of levels; each level maps entry key → snapshot */
    private array $stack = [];

    public function isActive(): bool
    {
        return $this->stack !== [];
    }

    public function begin(): void
    {
        $this->stack[] = [];
    }

    /**
     * Record the before-state of a map entry before it is modified in the current transaction.
     * Only records the first snapshot per key at each nesting level (idempotent).
     */
    public function snapshot(JournalEntry $entry): void
    {
        if ($this->stack === []) {
            return;
        }

        $level = count($this->stack) - 1;

        if (! isset($this->stack[$level][$entry->entryKey])) {
            $this->stack[$level][$entry->entryKey] = $entry;
        }
    }

    /**
     * Commit the innermost transaction level: merge its journal into the parent level
     * (the parent now inherits responsibility for any further rollback).
     */
    public function commit(): void
    {
        if ($this->stack === []) {
            return;
        }

        $committed = array_pop($this->stack);

        if ($this->stack !== []) {
            $parentLevel = count($this->stack) - 1;

            foreach ($committed as $key => $entry) {
                $this->stack[$parentLevel][$key] ??= $entry;
            }
        }
    }

    /**
     * Roll back the innermost level and return the snapshots to restore.
     *
     * @return list<JournalEntry>
     */
    public function rollback(): array
    {
        if ($this->stack === []) {
            return [];
        }

        return array_values(array_pop($this->stack));
    }

    public function flush(): void
    {
        $this->stack = [];
    }

    public function depth(): int
    {
        return count($this->stack);
    }
}
