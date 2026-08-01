<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Support;

final class EvictionBatch
{
    /**
     * How many entries a capped store sheds when it overflows: a tenth of the
     * cap (at least one). Large enough that breaches stay rare under sustained
     * churn, small enough that the hot nine-tenths of the cache survives.
     */
    public static function size(int $cap): int
    {
        return max(1, intdiv($cap, 10));
    }
}
