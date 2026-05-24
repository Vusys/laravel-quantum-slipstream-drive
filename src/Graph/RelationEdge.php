<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

use Vusys\QueryRicerExtreme\Enums\RelationKind;

final class RelationEdge
{
    public function __construct(
        public readonly ModelIdentity $from,
        public readonly string $relationName,
        public readonly RelationKind $kind,
        public readonly ModelIdentity $to,
        public readonly EdgeSource $source,
        public readonly EdgeConfidence $confidence,
        public int $version,
    ) {}
}
