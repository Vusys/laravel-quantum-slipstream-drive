<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

final class IdentityGraph
{
    /** @var array<string, list<RelationEdge>> */
    private array $edges = [];

    /** @var array<string, RelationCoverage> */
    private array $coverage = [];

    private int $edgeCount = 0;

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

        if ($this->maxEdges !== null && $this->edgeCount >= $this->maxEdges) {
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

        if ($this->maxCoverage !== null && count($this->coverage) >= $this->maxCoverage) {
            $this->flush();

            return;
        }

        $this->coverage[$key] = $coverage;
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
    }

    public function flush(): void
    {
        $this->edges = [];
        $this->coverage = [];
        $this->edgeCount = 0;
    }

    public function edgeCount(): int
    {
        return $this->edgeCount;
    }

    public function coverageCount(): int
    {
        return count($this->coverage);
    }
}
