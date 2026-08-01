<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

use Vusys\QuantumSlipstreamDrive\Predicate\PredicateNode;
use Vusys\QuantumSlipstreamDrive\Support\EvictionBatch;

final class IdentityGraph
{
    /**
     * Edge buckets and coverage grants are all kept in least-recently-used
     * order: every insert and hit moves the key to the end of its map, so
     * cap-breach eviction shears whole buckets (or grants) off the front
     * instead of flushing the graph.
     *
     * @var array<string, list<RelationEdge>>
     */
    private array $edges = [];

    /** @var array<string, RelationCoverage> */
    private array $coverage = [];

    /** @var array<string, list<PivotEdge>> */
    private array $pivotEdges = [];

    /** @var array<string, PivotCoverage> */
    private array $pivotCoverage = [];

    /**
     * Reverse-indexes: modelClass → set of bucket/coverage keys that have ever
     * referenced that class (as `from`/`to` for edges, as `parent`/`related`
     * for coverage). Maintained on insert only — entries may be stale when a
     * bucket has been cleared or its edges overwritten. Treated as hints by
     * invalidateModelClass(): we visit only the indexed keys and verify each
     * against the current state. flush() resets the indexes alongside the
     * primary maps.
     *
     * @var array<string, array<string, true>>
     */
    private array $edgesBucketsByClass = [];

    /** @var array<string, array<string, true>> */
    private array $coverageKeysByClass = [];

    /** @var array<string, array<string, true>> */
    private array $pivotEdgesBucketsByClass = [];

    /** @var array<string, array<string, true>> */
    private array $pivotCoverageKeysByClass = [];

    private int $edgeCount = 0;

    private int $pivotEdgeCount = 0;

    public function __construct(
        /** @var int|null null disables the cap (used by tests) */
        private readonly ?int $maxEdges = null,
        /** @var int|null null disables the cap */
        private readonly ?int $maxCoverage = null
    ) {}

    public function addEdge(RelationEdge $edge): void
    {
        $bucket = $edge->from->key().'|'.$edge->relationName;
        $existing = $this->edges[$bucket] ?? [];

        foreach ($existing as $i => $current) {
            if ($current->to->key() === $edge->to->key()) {
                $existing[$i] = $edge;
                unset($this->edges[$bucket]);
                $this->edges[$bucket] = $existing;
                $this->edgesBucketsByClass[$edge->to->modelClass][$bucket] = true;

                return;
            }
        }

        if ($this->maxEdges !== null && $this->totalEdgeCount() >= $this->maxEdges) {
            $this->evictColdestEdges();
            // Eviction may have dropped the very bucket being appended to; a
            // stale $existing would resurrect its evicted edges uncounted.
            $existing = $this->edges[$bucket] ?? [];
        }

        $existing[] = $edge;
        unset($this->edges[$bucket]);
        $this->edges[$bucket] = $existing;
        $this->edgeCount++;
        $this->edgesBucketsByClass[$edge->from->modelClass][$bucket] = true;
        $this->edgesBucketsByClass[$edge->to->modelClass][$bucket] = true;
    }

    public function addCoverage(RelationCoverage $coverage): void
    {
        $key = RelationCoverageKey::make($coverage->parent, $coverage->relationName);

        if (isset($this->coverage[$key])) {
            unset($this->coverage[$key]);
            $this->coverage[$key] = $coverage;
            $this->coverageKeysByClass[$coverage->relatedModelClass][$key] = true;

            return;
        }

        if ($this->maxCoverage !== null && $this->totalCoverageCount() >= $this->maxCoverage) {
            $this->evictColdestCoverage();
        }

        $this->coverage[$key] = $coverage;
        $this->coverageKeysByClass[$coverage->parent->modelClass][$key] = true;
        $this->coverageKeysByClass[$coverage->relatedModelClass][$key] = true;
    }

    public function addPivotEdge(PivotEdge $edge): void
    {
        $bucket = $edge->parent->key().'|'.$edge->relationName;
        $existing = $this->pivotEdges[$bucket] ?? [];

        foreach ($existing as $i => $current) {
            if ($current->related->key() === $edge->related->key()) {
                $existing[$i] = $edge;
                unset($this->pivotEdges[$bucket]);
                $this->pivotEdges[$bucket] = $existing;
                $this->pivotEdgesBucketsByClass[$edge->related->modelClass][$bucket] = true;

                return;
            }
        }

        if ($this->maxEdges !== null && $this->totalEdgeCount() >= $this->maxEdges) {
            $this->evictColdestEdges();
            // Eviction may have dropped the very bucket being appended to; a
            // stale $existing would resurrect its evicted edges uncounted.
            $existing = $this->pivotEdges[$bucket] ?? [];
        }

        $existing[] = $edge;
        unset($this->pivotEdges[$bucket]);
        $this->pivotEdges[$bucket] = $existing;
        $this->pivotEdgeCount++;
        $this->pivotEdgesBucketsByClass[$edge->parent->modelClass][$bucket] = true;
        $this->pivotEdgesBucketsByClass[$edge->related->modelClass][$bucket] = true;
    }

    public function addPivotCoverage(PivotCoverage $coverage): void
    {
        $key = RelationCoverageKey::make($coverage->parent, $coverage->relationName);

        if (isset($this->pivotCoverage[$key])) {
            unset($this->pivotCoverage[$key]);
            $this->pivotCoverage[$key] = $coverage;
            $this->pivotCoverageKeysByClass[$coverage->relatedModelClass][$key] = true;

            return;
        }

        if ($this->maxCoverage !== null && $this->totalCoverageCount() >= $this->maxCoverage) {
            $this->evictColdestCoverage();
        }

        $this->pivotCoverage[$key] = $coverage;
        $this->pivotCoverageKeysByClass[$coverage->parent->modelClass][$key] = true;
        $this->pivotCoverageKeysByClass[$coverage->relatedModelClass][$key] = true;
    }

    /** @return list<RelationEdge> */
    public function edgesFrom(ModelIdentity $from, string $relationName): array
    {
        $bucketKey = $from->key().'|'.$relationName;
        $bucket = $this->edges[$bucketKey] ?? null;

        if ($bucket === null) {
            return [];
        }

        unset($this->edges[$bucketKey]);
        $this->edges[$bucketKey] = $bucket;

        return $bucket;
    }

    public function coverageFor(ModelIdentity $parent, string $relationName): ?RelationCoverage
    {
        $key = RelationCoverageKey::make($parent, $relationName);
        $coverage = $this->coverage[$key] ?? null;

        if ($coverage !== null) {
            unset($this->coverage[$key]);
            $this->coverage[$key] = $coverage;
        }

        return $coverage;
    }

    /** @return list<PivotEdge> */
    public function pivotEdgesFrom(ModelIdentity $parent, string $relationName): array
    {
        $bucketKey = $parent->key().'|'.$relationName;
        $bucket = $this->pivotEdges[$bucketKey] ?? null;

        if ($bucket === null) {
            return [];
        }

        unset($this->pivotEdges[$bucketKey]);
        $this->pivotEdges[$bucketKey] = $bucket;

        return $bucket;
    }

    public function pivotCoverageFor(ModelIdentity $parent, string $relationName): ?PivotCoverage
    {
        $key = RelationCoverageKey::make($parent, $relationName);
        $coverage = $this->pivotCoverage[$key] ?? null;

        if ($coverage !== null) {
            unset($this->pivotCoverage[$key]);
            $this->pivotCoverage[$key] = $coverage;
        }

        return $coverage;
    }

    public function removePivotEdge(ModelIdentity $parent, string $relationName, ModelIdentity $related): void
    {
        $bucket = $parent->key().'|'.$relationName;
        $existing = $this->pivotEdges[$bucket] ?? null;

        if ($existing === null) {
            return;
        }

        $relatedKey = $related->key();
        $kept = [];

        foreach ($existing as $edge) {
            if ($edge->related->key() === $relatedKey) {
                $this->pivotEdgeCount--;

                continue;
            }

            $kept[] = $edge;
        }

        if ($kept === []) {
            unset($this->pivotEdges[$bucket]);
        } else {
            $this->pivotEdges[$bucket] = $kept;
        }
    }

    public function clearPivotEdgesFor(ModelIdentity $parent, string $relationName): void
    {
        $bucket = $parent->key().'|'.$relationName;
        $existing = $this->pivotEdges[$bucket] ?? null;

        if ($existing === null) {
            return;
        }

        $this->pivotEdgeCount -= count($existing);
        unset($this->pivotEdges[$bucket]);
    }

    public function forgetPivotCoverage(ModelIdentity $parent, string $relationName): void
    {
        unset($this->pivotCoverage[RelationCoverageKey::make($parent, $relationName)]);
    }

    public function invalidateModel(ModelIdentity $identity): void
    {
        $key = $identity->key();
        $prefix = $key.'|';

        foreach (array_keys($this->edges) as $bucketKey) {
            if (str_starts_with($bucketKey, $prefix)) {
                $this->edgeCount -= count($this->edges[$bucketKey]);
                unset($this->edges[$bucketKey]);

                continue;
            }

            $bucket = $this->edges[$bucketKey];
            $kept = [];

            foreach ($bucket as $edge) {
                if ($edge->to->key() === $key) {
                    $this->edgeCount--;

                    continue;
                }

                $kept[] = $edge;
            }

            if ($kept === []) {
                unset($this->edges[$bucketKey]);
            } else {
                $this->edges[$bucketKey] = $kept;
            }
        }

        foreach (array_keys($this->coverage) as $coverageKey) {
            if (str_starts_with($coverageKey, $prefix)) {
                unset($this->coverage[$coverageKey]);
            }
        }

        // A change to a related row can add it to (or remove it from) a recorded
        // predicate set. Filtered coverage records only the child keys that
        // matched at load time, so any write to the related class must drop it —
        // unfiltered coverage stays, since it serves fresh data straight from the
        // store and its membership is unaffected by an attribute change.
        foreach (array_keys($this->coverageKeysByClass[$identity->modelClass] ?? []) as $coverageKey) {
            $coverage = $this->coverage[$coverageKey] ?? null;

            if ($coverage instanceof RelationCoverage
                && $coverage->predicate instanceof PredicateNode
                && $coverage->relatedModelClass === $identity->modelClass
            ) {
                unset($this->coverage[$coverageKey]);
            }
        }

        foreach (array_keys($this->pivotEdges) as $bucketKey) {
            if (str_starts_with($bucketKey, $prefix)) {
                $this->pivotEdgeCount -= count($this->pivotEdges[$bucketKey]);
                unset($this->pivotEdges[$bucketKey]);

                continue;
            }

            $bucket = $this->pivotEdges[$bucketKey];
            $kept = [];

            foreach ($bucket as $edge) {
                if ($edge->related->key() === $key) {
                    $this->pivotEdgeCount--;

                    continue;
                }

                $kept[] = $edge;
            }

            if ($kept === []) {
                unset($this->pivotEdges[$bucketKey]);
            } else {
                $this->pivotEdges[$bucketKey] = $kept;
            }
        }

        foreach (array_keys($this->pivotCoverage) as $coverageKey) {
            if (str_starts_with($coverageKey, $prefix)) {
                unset($this->pivotCoverage[$coverageKey]);
            }
        }

        // A change to a related row can add it to (or remove it from) a filtered
        // pivot coverage's predicate set. Filtered coverage records only the edges
        // that matched at load time, so any write to the related class must drop it —
        // unfiltered pivot coverage stays, since it serves fresh data from the store.
        foreach (array_keys($this->pivotCoverageKeysByClass[$identity->modelClass] ?? []) as $coverageKey) {
            $coverage = $this->pivotCoverage[$coverageKey] ?? null;

            if ($coverage instanceof PivotCoverage
                && $coverage->predicate instanceof PredicateNode
                && $coverage->relatedModelClass === $identity->modelClass
            ) {
                unset($this->pivotCoverage[$coverageKey]);
            }
        }
    }

    public function invalidateModelClass(string $modelClass): void
    {
        $needle = '|'.$modelClass.'|';

        foreach (array_keys($this->edgesBucketsByClass[$modelClass] ?? []) as $bucketKey) {
            $bucket = $this->edges[$bucketKey] ?? null;

            if ($bucket === null) {
                continue;
            }

            if (str_contains($bucketKey, $needle)) {
                $this->edgeCount -= count($bucket);
                unset($this->edges[$bucketKey]);

                continue;
            }

            $kept = [];

            foreach ($bucket as $edge) {
                if ($edge->to->modelClass === $modelClass) {
                    $this->edgeCount--;

                    continue;
                }

                $kept[] = $edge;
            }

            if ($kept === []) {
                unset($this->edges[$bucketKey]);
            } else {
                $this->edges[$bucketKey] = $kept;
            }
        }
        unset($this->edgesBucketsByClass[$modelClass]);

        foreach (array_keys($this->coverageKeysByClass[$modelClass] ?? []) as $coverageKey) {
            $coverage = $this->coverage[$coverageKey] ?? null;

            if ($coverage === null) {
                continue;
            }

            if (
                str_contains($coverageKey, $needle)
                || $coverage->parent->modelClass === $modelClass
                || $coverage->relatedModelClass === $modelClass
            ) {
                unset($this->coverage[$coverageKey]);
            }
        }
        unset($this->coverageKeysByClass[$modelClass]);

        foreach (array_keys($this->pivotEdgesBucketsByClass[$modelClass] ?? []) as $bucketKey) {
            $bucket = $this->pivotEdges[$bucketKey] ?? null;

            if ($bucket === null) {
                continue;
            }

            if (str_contains($bucketKey, $needle)) {
                $this->pivotEdgeCount -= count($bucket);
                unset($this->pivotEdges[$bucketKey]);

                continue;
            }

            $kept = [];

            foreach ($bucket as $edge) {
                if ($edge->related->modelClass === $modelClass) {
                    $this->pivotEdgeCount--;

                    continue;
                }

                $kept[] = $edge;
            }

            if ($kept === []) {
                unset($this->pivotEdges[$bucketKey]);
            } else {
                $this->pivotEdges[$bucketKey] = $kept;
            }
        }
        unset($this->pivotEdgesBucketsByClass[$modelClass]);

        foreach (array_keys($this->pivotCoverageKeysByClass[$modelClass] ?? []) as $coverageKey) {
            $coverage = $this->pivotCoverage[$coverageKey] ?? null;

            if ($coverage === null) {
                continue;
            }

            if (
                str_contains($coverageKey, $needle)
                || $coverage->parent->modelClass === $modelClass
                || $coverage->relatedModelClass === $modelClass
            ) {
                unset($this->pivotCoverage[$coverageKey]);
            }
        }
        unset($this->pivotCoverageKeysByClass[$modelClass]);
    }

    /**
     * Shed the least-recently-used tenth of the edge budget instead of
     * flushing the graph. Whole buckets go at once, split proportionally
     * between plain and pivot edges, and each evicted bucket takes its
     * same-key coverage grant with it: unfiltered pivot coverage serves the
     * bucket's edges directly, so a surviving grant over a missing bucket
     * would answer with rows the database still has. Dropping bucket and
     * grant together only ever costs a fall-through to SQL.
     */
    private function evictColdestEdges(): void
    {
        if ($this->maxEdges === null) {
            return;
        }

        $total = $this->edgeCount + $this->pivotEdgeCount;

        if ($total === 0) {
            return;
        }

        $target = min(EvictionBatch::size($this->maxEdges), $total);
        $fromPlain = intdiv($target * $this->edgeCount, $total);

        $removed = $this->evictEdgeBucketsFromFront($fromPlain, pivot: false);
        $removed += $this->evictEdgeBucketsFromFront($target - $removed, pivot: true);

        if ($removed < $target) {
            $this->evictEdgeBucketsFromFront($target - $removed, pivot: false);
        }
    }

    private function evictEdgeBucketsFromFront(int $edgeTarget, bool $pivot): int
    {
        if ($edgeTarget <= 0) {
            return 0;
        }

        if ($pivot) {
            $edgeMap = &$this->pivotEdges;
            $coverageMap = &$this->pivotCoverage;
        } else {
            $edgeMap = &$this->edges;
            $coverageMap = &$this->coverage;
        }

        $removed = 0;

        foreach (array_keys($edgeMap) as $bucketKey) {
            $removed += count($edgeMap[$bucketKey]);
            unset($edgeMap[$bucketKey], $coverageMap[$bucketKey]);

            if ($removed >= $edgeTarget) {
                break;
            }
        }

        if ($pivot) {
            $this->pivotEdgeCount -= $removed;
        } else {
            $this->edgeCount -= $removed;
        }

        return $removed;
    }

    /**
     * Shed the least-recently-used tenth of the coverage budget. Coverage is
     * a pure grant — relation coverage re-resolves its child keys against the
     * live store, pivot coverage is dropped alongside its bucket — so an
     * evicted grant only sends the next relation read back to SQL.
     */
    private function evictColdestCoverage(): void
    {
        if ($this->maxCoverage === null) {
            return;
        }

        $total = count($this->coverage) + count($this->pivotCoverage);

        if ($total === 0) {
            return;
        }

        $target = min(EvictionBatch::size($this->maxCoverage), $total);
        $fromPivot = min($target - intdiv($target * count($this->coverage), $total), count($this->pivotCoverage));
        $fromPlain = min($target - $fromPivot, count($this->coverage));

        foreach (array_slice(array_keys($this->coverage), 0, $fromPlain) as $key) {
            unset($this->coverage[$key]);
        }

        foreach (array_slice(array_keys($this->pivotCoverage), 0, $fromPivot) as $key) {
            unset($this->pivotCoverage[$key]);
        }
    }

    public function flush(): void
    {
        $this->edges = [];
        $this->coverage = [];
        $this->pivotEdges = [];
        $this->pivotCoverage = [];
        $this->edgesBucketsByClass = [];
        $this->coverageKeysByClass = [];
        $this->pivotEdgesBucketsByClass = [];
        $this->pivotCoverageKeysByClass = [];
        $this->edgeCount = 0;
        $this->pivotEdgeCount = 0;
    }

    public function edgeCount(): int
    {
        return $this->edgeCount;
    }

    public function coverageCount(): int
    {
        return count($this->coverage);
    }

    public function pivotEdgeCount(): int
    {
        return $this->pivotEdgeCount;
    }

    public function pivotCoverageCount(): int
    {
        return count($this->pivotCoverage);
    }

    public function totalEdgeCount(): int
    {
        return $this->edgeCount + $this->pivotEdgeCount;
    }

    public function totalCoverageCount(): int
    {
        return count($this->coverage) + count($this->pivotCoverage);
    }
}
