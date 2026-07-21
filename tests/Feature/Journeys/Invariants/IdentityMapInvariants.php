<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RuntimeException;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\Runabout\Invariant;

/**
 * The universal invariant that every journey in this epic shares: a read served
 * from the identity map must be byte-for-byte identical to the same read with the
 * map disabled (raw SQL). This is the same oracle the differential fuzzers use,
 * now checked after every step of a seeded, shuffled trail.
 */
final class IdentityMapInvariants
{
    /**
     * Reading every row of $model through the engine must equal reading it with
     * the identity map disabled. Only $columns are compared, so a legitimately
     * partial cache entry never trips the check on an unrelated column.
     *
     * @param  class-string<Model>  $model
     * @param  non-empty-list<string>  $columns
     */
    public static function readsMatchBypass(string $model, array $columns): Invariant
    {
        return Invariant::make(
            sprintf('%s identity-map reads match a bypassed read', class_basename($model)),
            function () use ($model, $columns): void {
                $mapped = self::project($model, $columns);
                $bypassed = IdentityMap::disabled(static fn (): array => self::project($model, $columns));

                if ($mapped !== $bypassed) {
                    throw new RuntimeException(sprintf(
                        'identity-map read of %s diverged from a bypassed read: %s vs %s',
                        class_basename($model),
                        self::encode($mapped),
                        self::encode($bypassed),
                    ));
                }
            },
        );
    }

    /**
     * Project the whole table down to an ordered list of column maps, keyed by
     * primary key so the two reads line up row for row.
     *
     * @param  class-string<Model>  $model
     * @param  non-empty-list<string>  $columns
     * @return array<int|string, array<string, mixed>>
     */
    private static function project(string $model, array $columns): array
    {
        $key = (new $model)->getKeyName();

        $rows = [];
        foreach ($model::query()->orderBy($key)->get() as $row) {
            $rows[self::stringKey($row->getKey())] = Arr::only($row->getAttributes(), $columns);
        }

        return $rows;
    }

    private static function encode(mixed $rows): string
    {
        return json_encode($rows) ?: '[unencodable]';
    }

    private static function stringKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }
}
