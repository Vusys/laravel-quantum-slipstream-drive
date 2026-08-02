<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Coverage;

use Vusys\QuantumSlipstreamDrive\Predicate\PredicateColumns;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;
use Vusys\QuantumSlipstreamDrive\Support\EvictionBatch;

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

    /** Monotonic access clock behind {@see CoverageEntry::$lastTouched}. */
    private int $clock = 0;

    public function __construct(
        /** @var int|null null disables the cap */
        private readonly ?int $maxEntries = null,
    ) {}

    public function record(CoverageEntry $entry): void
    {
        if ($this->maxEntries !== null && $this->count >= $this->maxEntries) {
            $this->evictColdest();
        }

        $entry->lastTouched = ++$this->clock;
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
                $entry->lastTouched = ++$this->clock;

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

    /**
     * Drop every coverage entry that references one of the given evicted
     * primary keys. Called by the store when it sheds entries at its own cap:
     * serving already re-validates each recorded key against the live store
     * (a missing one falls through to SQL), so these entries could never
     * answer anything again — pruning them just stops dead grants from
     * squatting on this registry's cap.
     *
     * @param  array<string, array<int|string, true>>  $pksByClass  modelClass → set of evicted primary keys
     */
    public function evictReferencing(array $pksByClass): void
    {
        foreach ($pksByClass as $modelClass => $pks) {
            $bucket = $this->entries[$modelClass] ?? null;

            if ($bucket === null) {
                continue;
            }

            $kept = [];

            foreach ($bucket as $entry) {
                foreach ($entry->primaryKeys as $pk) {
                    if (isset($pks[$pk])) {
                        continue 2;
                    }
                }

                $kept[] = $entry;
            }

            $this->count -= count($bucket) - count($kept);

            if ($kept === []) {
                unset($this->entries[$modelClass]);
            } else {
                $this->entries[$modelClass] = $kept;
            }
        }
    }

    /**
     * Shed the least-recently-used tenth of the cap instead of flushing every
     * region. A coverage entry is a pure grant — permission to answer a query
     * from the store — so dropping one can only send a query back to SQL,
     * never answer it wrongly.
     */
    private function evictColdest(): void
    {
        if ($this->maxEntries === null) {
            return;
        }

        $all = [];

        foreach ($this->entries as $modelClass => $bucket) {
            foreach ($bucket as $i => $entry) {
                $all[] = [$entry->lastTouched, $modelClass, $i];
            }
        }

        usort($all, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $evictByClass = [];

        foreach (array_slice($all, 0, EvictionBatch::size($this->maxEntries)) as [, $modelClass, $i]) {
            $evictByClass[$modelClass][$i] = true;
        }

        foreach ($evictByClass as $modelClass => $indexes) {
            $bucket = $this->entries[$modelClass] ?? [];
            $kept = [];

            foreach ($bucket as $i => $entry) {
                if (! isset($indexes[$i])) {
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
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->count = 0;
        $this->clock = 0;
    }

    public function entryCount(): int
    {
        return $this->count;
    }
}
