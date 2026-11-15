<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Testing;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Vusys\QuantumSlipstreamDrive\IdentityMap;

/**
 * Checks the identity map against the database for your own models and
 * queries — the same oracle the package's fuzz tests use, as a one-liner.
 * Use it for anything the map hasn't been proven on: a model whose builder
 * another package owns, unusual scopes, writes that go round Eloquent.
 *
 * ```php
 * use AssertsIdentityMapAgrees;
 *
 * $this->assertIdentityMapAgrees(fn () => Department::query()->whereIsRoot()->get());
 * ```
 *
 * The read runs twice with the map on (the first fills it, the second may be
 * answered from it) and once with it off, and the answers must match: the
 * same models, by class and key, in the same order, with the same attribute
 * values. Relations a model carries are not compared — a cached model can
 * hold ones an earlier read loaded — so return them explicitly to check them.
 */
trait AssertsIdentityMapAgrees
{
    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T the answer the identity map gave
     */
    protected function assertIdentityMapAgrees(Closure $read, string $message = ''): mixed
    {
        $read();
        $mapped = $read();
        $database = IdentityMap::disabled($read);

        Assert::assertEquals(
            self::comparable($database),
            self::comparable($mapped),
            $message !== '' ? $message : 'The identity map answered differently from the database.',
        );

        return $mapped;
    }

    /**
     * A read's answer reduced to what a caller can observe: models by class,
     * key and cast attributes, recursively through collections and arrays.
     */
    private static function comparable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Model => [
                'model' => $value::class,
                'key' => $value->getKey(),
                'attributes' => self::sorted($value->attributesToArray()),
            ],
            $value instanceof Collection => array_map(self::comparable(...), $value->all()),
            is_array($value) => array_map(self::comparable(...), $value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            default => $value,
        };
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<array-key, mixed>
     */
    private static function sorted(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }
}
