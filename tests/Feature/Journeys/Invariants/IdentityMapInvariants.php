<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys\Invariants;

use Closure;
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
     * A specific query served through the engine must return the same rows as the
     * same query with the identity map disabled. This is the check for "no stale
     * collection is ever served from a covered region": the closure re-runs the
     * exact predicate that recorded the coverage, once each way.
     *
     * @param  Closure(): iterable<mixed>  $query  Re-runnable; returns model rows (e.g. ->get()->all()).
     * @param  non-empty-list<string>  $columns
     */
    public static function queryMatchesBypass(string $label, Closure $query, array $columns): Invariant
    {
        return Invariant::make(
            sprintf('coverage-served query [%s] matches a bypassed read', $label),
            function () use ($label, $query, $columns): void {
                $mapped = self::projectRows($query(), $columns);
                $bypassed = self::projectRows(IdentityMap::disabled($query), $columns);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('coverage-served query [%s] diverged from a bypassed read', $label),
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
        $instance = new $model;

        return self::projectRows($instance->newQuery()->orderBy($instance->getKeyName())->get(), $columns);
    }

    /**
     * Project an arbitrary set of model rows into the pk-keyed, column-scoped,
     * order-independent shape the two sides of an invariant compare. Accepts mixed
     * because both an engine collection and a map-disabled read come back loosely
     * typed; anything non-iterable (or a non-model row) simply contributes nothing.
     *
     * @param  non-empty-list<string>  $columns
     * @return array<string, array<string, mixed>>
     */
    private static function projectRows(mixed $rows, array $columns): array
    {
        $projected = [];

        if (is_iterable($rows)) {
            foreach ($rows as $row) {
                if (! $row instanceof Model) {
                    continue;
                }

                $values = [];
                foreach ($columns as $column) {
                    $values[$column] = $row->getAttribute($column);
                }

                $projected[self::stringKey($row->getKey())] = $values;
            }
        }

        ksort($projected);

        return $projected;
    }

    private static function stringKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }
}
