<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Coverage;

use Vusys\QuantumSlipstreamDrive\Predicate\PredicateColumns;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;

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

    /** Running total across all buckets, kept in sync by every mutator so the cap check stays O(1). */
    private int $count = 0;

    public function __construct(
        /** @var int|null null disables the cap */
        private readonly ?int $maxEntries = null,
    ) {}

    public function record(CoverageEntry $entry): void
    {
        if ($this->maxEntries !== null && $this->count >= $this->maxEntries) {
            $this->flush();

            return;
        }

        $this->entries[$entry->modelClass][] = $entry;
        $this->count++;
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
        $this->count -= count($this->entries[$modelClass] ?? []);
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

        $this->count -= count($bucket) - count($kept);

        if ($kept === []) {
            unset($this->entries[$modelClass]);
        } else {
            $this->entries[$modelClass] = $kept;
        }
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->count = 0;
    }

    public function entryCount(): int
    {
        return $this->count;
    }
}
