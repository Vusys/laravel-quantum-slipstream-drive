<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Enums\RelationKind;

final class RelationFact
{
    public function __construct(
        public readonly string $name,
        public readonly RelationKind $kind,
        public readonly bool $loaded,
        public readonly bool $complete,
        public readonly mixed $value,
    ) {}
}
