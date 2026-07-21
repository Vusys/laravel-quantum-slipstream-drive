<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Concerns;

use Vusys\Runabout\Context;

trait PicksIds
{
    /**
     * @param  array<array-key, mixed>  $ids
     */
    protected function pickId(Context $ctx, array $ids): ?int
    {
        $ids = array_values($ids);

        if ($ids === []) {
            return null;
        }

        $picked = $ctx->pick($ids);

        return is_numeric($picked) ? (int) $picked : null;
    }
}
