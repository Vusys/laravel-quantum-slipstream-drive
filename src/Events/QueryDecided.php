<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Events;

use Vusys\QueryRicerExtreme\Explanation;

final readonly class QueryDecided
{
    public function __construct(
        public Explanation $explanation,
    ) {}
}
