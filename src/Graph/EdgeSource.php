<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

enum EdgeSource: string
{
    case LoadedRelation = 'loaded_relation';
    case ForeignKeyFact = 'foreign_key_fact';
    case AssociationMutation = 'association_mutation';
    case Pivot = 'pivot';
}
