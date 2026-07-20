<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Predicate;

use Illuminate\Database\Query\Builder;

final class PredicateExtractor
{
    private const array SUPPORTED_OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<='];

    /** @param array<string, mixed> $where */
    public static function fromWhere(array $where): ?PredicateNode
    {
        $type = $where['type'] ?? null;

        if ($type === 'Nested') {
            return self::fromNested($where);
        }

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

    /**
     * Build a single predicate tree from a flat Laravel wheres list, honouring
     * boolean precedence: AND binds tighter than OR, so a run of AND-connected
     * terms forms one group and consecutive groups are combined under an OrNode.
     *
     * Returns null when any clause cannot be expressed as a predicate node, so
     * callers conservatively fall through to SQL.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     */
    public static function fromWheres(array $wheres): ?PredicateNode
    {
        /** @var list<list<PredicateNode>> $groups */
        $groups = [];
        /** @var list<PredicateNode> $current */
        $current = [];

        foreach (array_values($wheres) as $i => $where) {
            $boolean = $where['boolean'] ?? 'and';

            // whereNot()/orWhereNot() encode negation in the boolean ('and not' /
            // 'or not') rather than a flag on the where — including on a Nested
            // clause. We cannot represent that negation, so bail to SQL. (Positive
            // negated forms like whereNotIn / whereNotNull carry boolean 'and' and
            // their own where type, and are handled by fromWhere().)
            if ($boolean !== 'and' && $boolean !== 'or') {
                return null;
            }

            $node = self::fromWhere($where);

            if (! $node instanceof PredicateNode) {
                return null;
            }

            if ($i > 0 && $boolean === 'or') {
                $groups[] = $current;
                $current = [];
            }

            $current[] = $node;
        }

        $groups[] = $current;

        $branches = array_map(
            static fn (array $group): PredicateNode => count($group) === 1 ? $group[0] : new AndNode($group),
            $groups,
        );

        return count($branches) === 1 ? $branches[0] : new OrNode($branches);
    }

    /** @param array<string, mixed> $where */
    private static function fromNested(array $where): ?PredicateNode
    {
        $sub = $where['query'] ?? null;

        if (! $sub instanceof Builder) {
            return null;
        }

        return self::fromWheres($sub->wheres);
    }

    /** @param array<string, mixed> $where */
    private static function fromBasicWhere(string $column, array $where): ?PredicateNode
    {
        $operator = $where['operator'] ?? null;

        if (! is_string($operator)) {
            return null;
        }

        $normalized = strtolower($operator);

        if ($normalized === 'like' || $normalized === 'not like') {
            $value = $where['value'] ?? null;

            return is_string($value)
                ? new LikeNode($column, $value, $normalized === 'not like')
                : null;
        }

        if (! in_array($operator, self::SUPPORTED_OPERATORS, true)) {
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

        return new InNode($column, array_values($values), $negated);
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
