<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

final class IdentityGraph
{
    /** @var array<string, list<RelationEdge>> */
    private array $edges = [];

    /** @var array<string, RelationCoverage> */
    private array $coverage = [];

    /** @var array<string, list<PivotEdge>> */
    private array $pivotEdges = [];

    /** @var array<string, PivotCoverage> */
    private array $pivotCoverage = [];

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
                $this->edges[$bucket] = $existing;

                return;
            }
        }

        if ($this->maxEdges !== null && $this->totalEdgeCount() >= $this->maxEdges) {
            $this->flush();

            return;
        }

        $existing[] = $edge;
        $this->edges[$bucket] = $existing;
        $this->edgeCount++;
    }

    public function addCoverage(RelationCoverage $coverage): void
    {
        $key = RelationCoverageKey::make($coverage->parent, $coverage->relationName);

        if (isset($this->coverage[$key])) {
            $this->coverage[$key] = $coverage;

            return;
        }

        if ($this->maxCoverage !== null && $this->totalCoverageCount() >= $this->maxCoverage) {
            $this->flush();

            return;
        }

        $this->coverage[$key] = $coverage;
    }

    public function addPivotEdge(PivotEdge $edge): void
    {
        $bucket = $edge->parent->key().'|'.$edge->relationName;
        $existing = $this->pivotEdges[$bucket] ?? [];

        foreach ($existing as $i => $current) {
            if ($current->related->key() === $edge->related->key()) {
                $existing[$i] = $edge;
                $this->pivotEdges[$bucket] = $existing;

                return;
            }
        }

        if ($this->maxEdges !== null && $this->totalEdgeCount() >= $this->maxEdges) {
            $this->flush();

            return;
        }

        $existing[] = $edge;
        $this->pivotEdges[$bucket] = $existing;
        $this->pivotEdgeCount++;
    }

    public function addPivotCoverage(PivotCoverage $coverage): void
    {
        $key = RelationCoverageKey::make($coverage->parent, $coverage->relationName);

        if (isset($this->pivotCoverage[$key])) {
            $this->pivotCoverage[$key] = $coverage;

            return;
        }

        if ($this->maxCoverage !== null && $this->totalCoverageCount() >= $this->maxCoverage) {
            $this->flush();

            return;
        }

        $this->pivotCoverage[$key] = $coverage;
    }

    /** @return list<RelationEdge> */
    public function edgesFrom(ModelIdentity $from, string $relationName): array
    {
        return $this->edges[$from->key().'|'.$relationName] ?? [];
    }

    public function coverageFor(ModelIdentity $parent, string $relationName): ?RelationCoverage
    {
        return $this->coverage[RelationCoverageKey::make($parent, $relationName)] ?? null;
    }

    /** @return list<PivotEdge> */
    public function pivotEdgesFrom(ModelIdentity $parent, string $relationName): array
    {
        return $this->pivotEdges[$parent->key().'|'.$relationName] ?? [];
    }

    public function pivotCoverageFor(ModelIdentity $parent, string $relationName): ?PivotCoverage
    {
        return $this->pivotCoverage[RelationCoverageKey::make($parent, $relationName)] ?? null;
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
    }

    public function invalidateModelClass(string $modelClass): void
    {
        $needle = '|'.$modelClass.'|';

        foreach (array_keys($this->edges) as $bucketKey) {
            $bucket = $this->edges[$bucketKey];

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

        foreach (array_keys($this->coverage) as $coverageKey) {
            if (str_contains($coverageKey, $needle)) {
                unset($this->coverage[$coverageKey]);

                continue;
            }

            $coverage = $this->coverage[$coverageKey];

            if (
                $coverage->parent->modelClass === $modelClass
                || $coverage->relatedModelClass === $modelClass
            ) {
                unset($this->coverage[$coverageKey]);
            }
        }

        foreach (array_keys($this->pivotEdges) as $bucketKey) {
            $bucket = $this->pivotEdges[$bucketKey];

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

        foreach (array_keys($this->pivotCoverage) as $coverageKey) {
            if (str_contains($coverageKey, $needle)) {
                unset($this->pivotCoverage[$coverageKey]);

                continue;
            }

            $coverage = $this->pivotCoverage[$coverageKey];

            if (
                $coverage->parent->modelClass === $modelClass
                || $coverage->relatedModelClass === $modelClass
            ) {
                unset($this->pivotCoverage[$coverageKey]);
            }
        }
    }

    public function flush(): void
    {
        $this->edges = [];
        $this->coverage = [];
        $this->pivotEdges = [];
        $this->pivotCoverage = [];
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
