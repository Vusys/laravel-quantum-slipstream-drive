<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Coverage;

use Vusys\QueryRicerExtreme\Predicate\PredicateColumns;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class CoverageRegistry
{
    /**
     * Entries bucketed by modelClass so findCovering / flushModelClass /
     * flushByColumns visit only the class's slice instead of scanning every
     * entry every call.
     *
     * @var array<string, list<CoverageEntry>>
     */
    private array $entries = [];

    public function __construct(
        /** @var int|null null disables the cap */
        private readonly ?int $maxEntries = null,
    ) {}

    public function record(CoverageEntry $entry): void
    {
        if ($this->maxEntries !== null && $this->entryCount() >= $this->maxEntries) {
            $this->flush();

            return;
        }

        $this->entries[$entry->modelClass][] = $entry;
    }

    public function findCovering(
        string $modelClass,
        string $connection,
        string $table,
        string $scopeFingerprint,
        PredicateNode $queryRegion,
    ): ?CoverageEntry {
        $bucket = $this->entries[$modelClass] ?? null;

        if ($bucket === null) {
            return null;
        }

        $checker = new SubsetChecker;

        foreach ($bucket as $entry) {
            if ($entry->connection !== $connection) {
                continue;
            }

            if ($entry->table !== $table) {
                continue;
            }

            if ($entry->scopeFingerprint !== $scopeFingerprint) {
                continue;
            }

            if (! $entry->complete) {
                continue;
            }

            if ($checker->isSubset($queryRegion, $entry->region)) {
                return $entry;
            }
        }

        return null;
    }

    public function flushModelClass(string $modelClass): void
    {
        unset($this->entries[$modelClass]);
    }

    /**
     * Flush only coverage entries for $modelClass whose region predicate references
     * at least one of the given columns. Entries whose regions are disjoint from the
     * changed columns are preserved.
     *
     * @param  list<string>  $changedColumns
     */
    public function flushByColumns(string $modelClass, array $changedColumns): void
    {
        if ($changedColumns === []) {
            return;
        }

        $bucket = $this->entries[$modelClass] ?? null;

        if ($bucket === null) {
            return;
        }

        $kept = [];

        foreach ($bucket as $entry) {
            $regionColumns = PredicateColumns::fromNode($entry->region);
            $touched = false;

            foreach ($changedColumns as $col) {
                if (in_array($col, $regionColumns, true)) {
                    $touched = true;
                    break;
                }
                if (! $entry->columns->allColumns && $entry->columns->covers([$col])) {
                    $touched = true;
                    break;
                }
            }

            if (! $touched) {
                $kept[] = $entry;
            }
        }

        if ($kept === []) {
            unset($this->entries[$modelClass]);
        } else {
            $this->entries[$modelClass] = $kept;
        }
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    public function entryCount(): int
    {
        $count = 0;

        foreach ($this->entries as $bucket) {
            $count += count($bucket);
        }

        return $count;
    }
}
