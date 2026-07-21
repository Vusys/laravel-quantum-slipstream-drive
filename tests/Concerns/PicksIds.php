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

    /**
     * The string-keyed sibling of pickId(), for models with non-incrementing
     * primary keys (UUIDs). Returns the picked key verbatim as a string so the
     * caller can whereKey()/find() a UuidUser without losing precision to an int
     * cast.
     *
     * @param  array<array-key, mixed>  $keys
     */
    protected function pickKey(Context $ctx, array $keys): ?string
    {
        $keys = array_values($keys);

        if ($keys === []) {
            return null;
        }

        $picked = $ctx->pick($keys);

        return is_scalar($picked) ? (string) $picked : null;
    }
}
