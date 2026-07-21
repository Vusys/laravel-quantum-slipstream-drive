<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
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

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('identity-map read of %s diverged from a bypassed read', class_basename($model)),
                );
            },
        );
    }

    /**
     * Project the whole table down to an ordered list of column maps, keyed by
     * primary key so the two reads line up row for row. Reads cast values
     * (getAttribute) so an in-memory mutation and a fresh DB read are compared on
     * equal footing — the difference we care about is the value, not whether it
     * is stored as an int or a bool.
     *
     * @param  class-string<Model>  $model
     * @param  non-empty-list<string>  $columns
     * @return array<string, array<string, mixed>>
     */
    private static function project(string $model, array $columns): array
    {
        $key = (new $model)->getKeyName();

        $rows = [];
        foreach ($model::query()->orderBy($key)->get() as $row) {
            $projected = [];
            foreach ($columns as $column) {
                $projected[$column] = $row->getAttribute($column);
            }

            $rows[self::stringKey($row->getKey())] = $projected;
        }

        return $rows;
    }

    private static function stringKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }
}
