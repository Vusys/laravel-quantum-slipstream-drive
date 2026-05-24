<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

final class PredicateExtractor
{
    private const array SUPPORTED_OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<='];

    /** @param array<string, mixed> $where */
    public static function fromWhere(array $where): ?PredicateNode
    {
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;

        if (! is_string($column) || $column === '') {
            return null;
        }

        return match ($type) {
            'Basic' => self::fromBasicWhere($column, $where),
            'In' => self::fromInWhere($column, $where, false),
            'NotIn' => self::fromInWhere($column, $where, true),
            'Null' => new NullNode($column, false),
            'NotNull' => new NullNode($column, true),
            'between', 'Between' => self::fromBetweenWhere($column, $where),
            default => null,
        };
    }

    /** @param array<string, mixed> $where */
    private static function fromBasicWhere(string $column, array $where): ?PredicateNode
    {
        $operator = $where['operator'] ?? null;

        if (! is_string($operator) || ! in_array($operator, self::SUPPORTED_OPERATORS, true)) {
            return null;
        }

        return new ComparisonNode($column, $operator, $where['value'] ?? null);
    }

    /** @param array<string, mixed> $where */
    private static function fromInWhere(string $column, array $where, bool $negated): ?InNode
    {
        $values = $where['values'] ?? null;

        if (! is_array($values)) {
            return null;
        }

        foreach ($values as $v) {
            if ($v === null || ! is_scalar($v)) {
                return null;
            }
        }

        /** @var list<mixed> $values */
        return new InNode($column, $values, $negated);
    }

    /** @param array<string, mixed> $where */
    private static function fromBetweenWhere(string $column, array $where): ?BetweenNode
    {
        $values = $where['values'] ?? null;
        $not = (bool) ($where['not'] ?? false);

        if (! is_array($values) || count($values) !== 2) {
            return null;
        }

        [$min, $max] = array_values($values);

        if (! is_scalar($min) || ! is_scalar($max)) {
            return null;
        }

        return new BetweenNode($column, $min, $max, $not);
    }
}
