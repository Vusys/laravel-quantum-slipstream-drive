<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Events;

use Vusys\QuantumSlipstreamDrive\Explanation;

final readonly class QueryDecided
{
    public function __construct(
        public Explanation $explanation,
    ) {}
}
