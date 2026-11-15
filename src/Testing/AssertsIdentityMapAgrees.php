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
     * key and every stored attribute's cast value (hidden ones included),
     * recursively through collections and arrays — which become ordered
     * [key, value] pairs, since a comparison of keyed arrays ignores order.
     */
    private static function comparable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Model => [
                'model' => $value::class,
                'key' => $value->getKey(),
                'attributes' => self::attributes($value),
            ],
            $value instanceof Collection => self::pairs($value->all()),
            is_array($value) => self::pairs($value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            default => $value,
        };
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<array{0: array-key, 1: mixed}>
     */
    private static function pairs(array $items): array
    {
        $pairs = [];
        foreach ($items as $key => $item) {
            $pairs[] = [$key, self::comparable($item)];
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    private static function attributes(Model $model): array
    {
        $attributes = [];
        foreach (array_keys($model->getAttributes()) as $name) {
            $attributes[$name] = self::comparable($model->getAttributeValue($name));
        }

        ksort($attributes);

        return $attributes;
    }
}
