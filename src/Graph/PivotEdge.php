<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

final class PivotEdge
{
    /** @param array<string, mixed> $pivotAttributes */
    public function __construct(
        public readonly ModelIdentity $parent,
        public readonly string $relationName,
        public readonly ModelIdentity $related,
        public readonly string $pivotTable,
        public array $pivotAttributes,
        public readonly EdgeSource $source,
        public readonly EdgeConfidence $confidence,
        public int $version,
    ) {}
}
