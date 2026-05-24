<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Graph;

enum EdgeSource: string
{
    case LoadedRelation = 'loaded_relation';
    case ForeignKeyFact = 'foreign_key_fact';
    case AssociationMutation = 'association_mutation';
}
