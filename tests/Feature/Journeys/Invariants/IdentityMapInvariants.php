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
     * The order-sensitive sibling of queryMatchesBypass: the rows an ordered
     * query returns through the engine must match a map-disabled read *in the same
     * sequence*. queryMatchesBypass keys by primary key and sorts, so it can never
     * catch a wrong order; this one preserves the returned sequence as a list and
     * compares position by position. It is the guard for "byte-for-byte identical
     * rows must also mean identical order" — the property the Postgres
     * locale-collation fix restored.
     *
     * @param  Closure(): iterable<mixed>  $query  Re-runnable ordered query (e.g. ->get()->all()).
     * @param  non-empty-list<string>  $columns
     */
    public static function orderedQueryMatchesBypass(string $label, Closure $query, array $columns): Invariant
    {
        return Invariant::make(
            sprintf('ordered query [%s] matches a bypassed read', $label),
            function () use ($label, $query, $columns): void {
                $mapped = self::projectSequence($query(), $columns);
                $bypassed = self::projectSequence(IdentityMap::disabled($query), $columns);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('ordered query [%s] served rows in a different order than a bypassed read', $label),
                );
            },
        );
    }

    /**
     * Every parent's children, read through the engine's relation graph, must
     * equal the same children read with the map disabled. This is the check that
     * "relation reads never return deleted or stale children": for each parent the
     * $children closure re-runs the relation query, once each way.
     *
     * @param  class-string<Model>  $parentModel
     * @param  Closure(Model): mixed  $children  Given a parent, return its child rows (e.g. $p->posts()->get()).
     * @param  non-empty-list<string>  $childColumns
     */
    public static function relationMatchesBypass(string $label, string $parentModel, Closure $children, array $childColumns): Invariant
    {
        $project = static function () use ($parentModel, $children, $childColumns): array {
            $instance = new $parentModel;

            $out = [];
            foreach ($instance->newQuery()->orderBy($instance->getKeyName())->get() as $parent) {
                $out[self::stringKey($parent->getKey())] = self::projectRows($children($parent), $childColumns);
            }

            ksort($out);

            return $out;
        };

        return Invariant::make(
            sprintf('relation [%s] reads match a bypassed read', $label),
            function () use ($label, $project): void {
                $mapped = $project();
                $bypassed = IdentityMap::disabled($project);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('relation [%s] served a deleted or stale child set', $label),
                );
            },
        );
    }

    /**
     * Like relationMatchesBypass, but also compares each child's *pivot*
     * attributes. For every parent the $childrenOf closure returns the related
     * rows (each carrying a ->pivot); the child is reduced to its $childColumns
     * plus its normalized $pivotColumns, so a sync/detach/updateExistingPivot the
     * engine's PivotCoverage failed to invalidate — a stale pivot flag, a detached
     * row still served, a priority left at its old value — is caught after every
     * step.
     *
     * @param  class-string<Model>  $parentModel
     * @param  Closure(Model): mixed  $childrenOf
     * @param  non-empty-list<string>  $childColumns
     * @param  non-empty-list<string>  $pivotColumns
     */
    public static function relationPivotMatchesBypass(
        string $label,
        string $parentModel,
        Closure $childrenOf,
        array $childColumns,
        array $pivotColumns,
    ): Invariant {
        $project = static function () use ($parentModel, $childrenOf, $childColumns, $pivotColumns): array {
            $instance = new $parentModel;

            $out = [];
            foreach ($instance->newQuery()->orderBy($instance->getKeyName())->get() as $parent) {
                $children = $childrenOf($parent);
                $rows = [];

                if (is_iterable($children)) {
                    foreach ($children as $child) {
                        if (! $child instanceof Model) {
                            continue;
                        }

                        $values = [];
                        foreach ($childColumns as $column) {
                            $values[$column] = $child->getAttribute($column);
                        }

                        $pivot = $child->getAttribute('pivot');
                        $pivotValues = [];
                        foreach ($pivotColumns as $column) {
                            $pivotValues[$column] = $pivot instanceof Model ? self::normalizeCast($pivot->getAttribute($column)) : null;
                        }
                        $values['@pivot'] = $pivotValues;

                        $rows[self::stringKey($child->getKey())] = $values;
                    }
                }

                ksort($rows);
                $out[self::stringKey($parent->getKey())] = $rows;
            }

            ksort($out);

            return $out;
        };

        return Invariant::make(
            sprintf('relation+pivot [%s] reads match a bypassed read', $label),
            function () use ($label, $project): void {
                $mapped = $project();
                $bypassed = IdentityMap::disabled($project);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('relation+pivot [%s] served a stale pivot or detached child', $label),
                );
            },
        );
    }

    /**
     * The cast-aware sibling of readsMatchBypass: every row of $model, read once
     * through the engine and once with the map disabled, must be equal after each
     * cast column is normalized to a comparable scalar (a Carbon to a formatted
     * string, a backed enum to its value). Two reads that decode to the same value
     * would otherwise fail assertSame on object identity, hiding the property we
     * actually care about — that a memory-served row survives the cast boundary
     * identically to a fresh DB read.
     *
     * @param  class-string<Model>  $model
     * @param  non-empty-list<string>  $columns
     */
    public static function castReadsMatchBypass(string $model, array $columns): Invariant
    {
        $project = static function () use ($model, $columns): array {
            $instance = new $model;

            $out = [];
            foreach ($instance->newQuery()->orderBy($instance->getKeyName())->get() as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[$column] = self::normalizeCast($row->getAttribute($column));
                }

                $out[self::stringKey($row->getKey())] = $values;
            }

            ksort($out);

            return $out;
        };

        return Invariant::make(
            sprintf('%s cast-normalized reads match a bypassed read', class_basename($model)),
            function () use ($model, $project): void {
                $mapped = $project();
                $bypassed = IdentityMap::disabled($project);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('cast-normalized read of %s diverged from a bypassed read', class_basename($model)),
                );
            },
        );
    }

    /**
     * An aggregate (count / sum / min / max / exists) served through the engine
     * from a covered region must equal the same aggregate with the map disabled.
     * Both sides run on the same connection, so any divergence is the engine
     * computing a stale total from memory rather than a driver quirk. The closure
     * re-runs the exact aggregate once each way.
     *
     * @param  Closure(): mixed  $aggregate
     */
    public static function aggregateMatchesBypass(string $label, Closure $aggregate): Invariant
    {
        return Invariant::make(
            sprintf('aggregate [%s] matches a bypassed read', $label),
            function () use ($label, $aggregate): void {
                $mapped = $aggregate();
                $bypassed = IdentityMap::disabled($aggregate);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('aggregate [%s] diverged from a bypassed read', $label),
                );
            },
        );
    }

    /**
     * A find-by-unique-column resolution served through the engine (e.g.
     * firstWhere('email', ...) answered from UniqueKeyIndex) must resolve to the
     * same row — or the same null — as a map-disabled read. The closure returns a
     * scalar identity of the resolved row (its key, or null when absent), run once
     * each way; a stale index that still maps a renamed-away or deleted unique
     * value to its old row surfaces as the two diverging.
     *
     * @param  Closure(): mixed  $resolve
     */
    public static function lookupMatchesBypass(string $label, Closure $resolve): Invariant
    {
        return Invariant::make(
            sprintf('unique lookup [%s] matches a bypassed read', $label),
            function () use ($label, $resolve): void {
                $mapped = $resolve();
                $bypassed = IdentityMap::disabled($resolve);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('unique lookup [%s] resolved a stale or wrong row', $label),
                );
            },
        );
    }

    /**
     * Every child's resolved polymorphic parent, read through the engine, must
     * equal the same parent read with the map disabled. This is the inverse of
     * relationMatchesBypass for a morphTo: for each child the $parentOf closure
     * resolves ->commentable (or any morphTo) once each way, and the parent is
     * reduced to its morph class + key so a stale MemoryMorphTo that keeps
     * pointing at a reparented or deleted owner is caught.
     *
     * @param  class-string<Model>  $childModel
     * @param  Closure(Model): ?Model  $parentOf  Given a child, return its resolved morph parent (or null).
     */
    public static function morphParentMatchesBypass(string $label, string $childModel, Closure $parentOf): Invariant
    {
        $project = static function () use ($childModel, $parentOf): array {
            $instance = new $childModel;

            $out = [];
            foreach ($instance->newQuery()->orderBy($instance->getKeyName())->get() as $child) {
                $parent = $parentOf($child);

                $out[self::stringKey($child->getKey())] = $parent instanceof Model
                    ? ['type' => $parent->getMorphClass(), 'key' => self::stringKey($parent->getKey())]
                    : null;
            }

            ksort($out);

            return $out;
        };

        return Invariant::make(
            sprintf('morph parent [%s] reads match a bypassed read', $label),
            function () use ($label, $project): void {
                $mapped = $project();
                $bypassed = IdentityMap::disabled($project);

                Assert::assertSame(
                    $bypassed,
                    $mapped,
                    sprintf('morph parent [%s] resolved a reparented or deleted owner', $label),
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

    /**
     * Project rows into an order-preserving list of column maps — the same
     * per-row shape projectRows produces, but as a sequence keyed by position
     * instead of primary key, so the comparison is sensitive to order.
     *
     * @param  non-empty-list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private static function projectSequence(mixed $rows, array $columns): array
    {
        $sequence = [];

        if (is_iterable($rows)) {
            foreach ($rows as $row) {
                if (! $row instanceof Model) {
                    continue;
                }

                $values = [];
                foreach ($columns as $column) {
                    $values[$column] = $row->getAttribute($column);
                }

                $sequence[] = $values;
            }
        }

        return $sequence;
    }

    private static function stringKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }

    /**
     * Reduce a cast attribute to a value comparable across two independent reads:
     * backed enums to their scalar, date/times to a microsecond-precise string,
     * arrays element-wise, everything else untouched.
     */
    private static function normalizeCast(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_array($value)) {
            return array_map(self::normalizeCast(...), $value);
        }

        return $value;
    }
}
