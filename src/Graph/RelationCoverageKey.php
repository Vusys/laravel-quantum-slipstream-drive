<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

final class RelationCoverageKey
{
    public static function make(ModelIdentity $parent, string $relationName): string
    {
        return $parent->key().'|'.$relationName;
    }
}
