<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves per-column semantics (type, collation, comparison mode) for use
 * by DriverSemantics implementations during predicate evaluation.
 */
interface ColumnSemanticsResolver
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function for(string $modelClass, string $column): ColumnSemantics;
}
