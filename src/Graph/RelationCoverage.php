<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

use Vusys\QueryRicerExtreme\Coverage\ColumnSet;

final readonly class RelationCoverage
{
    /** @param list<int|string> $childPrimaryKeys */
    public function __construct(
        public ModelIdentity $parent,
        public string $relationName,
        public string $relatedModelClass,
        public bool $complete,
        public ColumnSet $columns,
        public array $childPrimaryKeys,
    ) {}
}
