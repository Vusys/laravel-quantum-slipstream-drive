<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

/**
 * Default resolver: returns ColumnSemantics::unknown() for every column.
 *
 * Used when no schema metadata is available. Driver profiles that require
 * known column semantics should return Unknown rather than guess.
 */
final class NullColumnSemanticsResolver implements ColumnSemanticsResolver
{
    #[\Override]
    public function for(string $modelClass, string $column): ColumnSemantics
    {
        return ColumnSemantics::unknown();
    }
}
