<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Predicate\AndNode;
use Vusys\QuantumSlipstreamDrive\Predicate\BetweenNode;
use Vusys\QuantumSlipstreamDrive\Predicate\ComparisonNode;
use Vusys\QuantumSlipstreamDrive\Predicate\InNode;
use Vusys\QuantumSlipstreamDrive\Predicate\LikeNode;
use Vusys\QuantumSlipstreamDrive\Predicate\NullNode;
use Vusys\QuantumSlipstreamDrive\Predicate\OrNode;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateExtractor;

final class PredicateExtractorTest extends TestCase
{
    #[Test]
    public function extracts_equality_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'status',
            'operator' => '=',
            'value' => 'active',
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('status', $node->column);
        $this->assertSame('=', $node->operator);
        $this->assertSame('active', $node->value);
    }

    #[Test]
    public function extracts_not_equal_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'status',
            'operator' => '!=',
            'value' => 'disabled',
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('!=', $node->operator);
    }

    #[Test]
    public function extracts_diamond_not_equal_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'status',
            'operator' => '<>',
            'value' => 'disabled',
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('<>', $node->operator);
    }

    #[Test]
    public function unsupported_basic_operator_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'name',
            'operator' => 'ilike',
            'value' => 'A%',
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function like_operator_extracts_to_like_node(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'name',
            'operator' => 'like',
            'value' => 'A%',
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(LikeNode::class, $node);
        $this->assertSame('name', $node->column);
        $this->assertSame('A%', $node->pattern);
        $this->assertFalse($node->negated);
    }

    #[Test]
    public function extracts_in_node(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'In',
            'column' => 'status',
            'values' => ['active', 'pending'],
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(InNode::class, $node);
        $this->assertSame('status', $node->column);
        $this->assertSame(['active', 'pending'], $node->values);
        $this->assertFalse($node->negated);
    }

    #[Test]
    public function extracts_not_in_node(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'NotIn',
            'column' => 'status',
            'values' => ['disabled', 'banned'],
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(InNode::class, $node);
        $this->assertTrue($node->negated);
    }

    #[Test]
    public function in_node_with_non_scalar_values_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'In',
            'column' => 'ids',
            'values' => [new \stdClass],
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function in_node_with_null_value_in_list_returns_null(): void
    {
        // SQL: `x IN (1, NULL)` is UNKNOWN when x != 1 — fall back to SQL.
        $node = PredicateExtractor::fromWhere([
            'type' => 'In',
            'column' => 'status',
            'values' => ['active', null],
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function not_in_node_with_null_value_in_list_returns_null(): void
    {
        // SQL: `x NOT IN (1, NULL)` is always UNKNOWN — fall back to SQL.
        $node = PredicateExtractor::fromWhere([
            'type' => 'NotIn',
            'column' => 'status',
            'values' => ['disabled', null],
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function extracts_null_node(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Null',
            'column' => 'deleted_at',
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(NullNode::class, $node);
        $this->assertSame('deleted_at', $node->column);
        $this->assertFalse($node->negated);
    }

    #[Test]
    public function extracts_not_null_node(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'NotNull',
            'column' => 'email',
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(NullNode::class, $node);
        $this->assertTrue($node->negated);
    }

    #[Test]
    public function unsupported_where_type_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Raw',
            'sql' => 'LOWER(email) = ?',
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function nested_where_type_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Nested',
            'column' => null,
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function in_raw_type_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'InRaw',
            'column' => 'id',
            'values' => [1, 2, 3],
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function missing_column_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'operator' => '=',
            'value' => 'x',
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function extracts_greater_than_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'age',
            'operator' => '>',
            'value' => 18,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('>', $node->operator);
        $this->assertSame(18, $node->value);
    }

    #[Test]
    public function extracts_greater_than_or_equal_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'age',
            'operator' => '>=',
            'value' => 18,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('>=', $node->operator);
    }

    #[Test]
    public function extracts_less_than_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'age',
            'operator' => '<',
            'value' => 65,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('<', $node->operator);
    }

    #[Test]
    public function extracts_less_than_or_equal_comparison(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'age',
            'operator' => '<=',
            'value' => 65,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(ComparisonNode::class, $node);
        $this->assertSame('<=', $node->operator);
    }

    #[Test]
    public function extracts_between_lowercase_type(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => [18, 65],
            'not' => false,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(BetweenNode::class, $node);
        $this->assertSame('age', $node->column);
        $this->assertSame(18, $node->min);
        $this->assertSame(65, $node->max);
        $this->assertFalse($node->negated);
    }

    #[Test]
    public function extracts_between_capitalised_type(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Between',
            'column' => 'age',
            'values' => [18, 65],
            'not' => false,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(BetweenNode::class, $node);
        $this->assertFalse($node->negated);
    }

    #[Test]
    public function extracts_not_between(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => [18, 65],
            'not' => true,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(BetweenNode::class, $node);
        $this->assertTrue($node->negated);
    }

    #[Test]
    public function between_with_wrong_value_count_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => [18],
            'not' => false,
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function between_with_non_scalar_value_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => [18, new \stdClass],
            'not' => false,
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function between_with_missing_values_returns_null(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'not' => false,
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function between_defaults_to_non_negated_when_not_key_missing(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => [18, 65],
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(BetweenNode::class, $node);
        $this->assertFalse($node->negated);
    }

    #[Test]
    public function between_with_associative_values_array_resolves_min_and_max(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => ['from' => 18, 'to' => 65],
            'not' => false,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(BetweenNode::class, $node);
        $this->assertSame(18, $node->min);
        $this->assertSame(65, $node->max);
    }

    #[Test]
    public function between_coerces_truthy_non_bool_not_flag(): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'between',
            'column' => 'age',
            'values' => [18, 65],
            'not' => 1,
            'boolean' => 'and',
        ]);

        $this->assertInstanceOf(BetweenNode::class, $node);
        $this->assertTrue($node->negated);
    }

    // --- fromWheres: boolean precedence ---

    #[Test]
    public function from_wheres_all_and_builds_and_node(): void
    {
        $node = PredicateExtractor::fromWheres([
            ['type' => 'Basic', 'column' => 'a', 'operator' => '=', 'value' => 1, 'boolean' => 'and'],
            ['type' => 'Basic', 'column' => 'b', 'operator' => '=', 'value' => 2, 'boolean' => 'and'],
        ]);

        $this->assertInstanceOf(AndNode::class, $node);
        $this->assertCount(2, $node->children);
    }

    #[Test]
    public function from_wheres_top_level_or_builds_or_node(): void
    {
        $node = PredicateExtractor::fromWheres([
            ['type' => 'Basic', 'column' => 'a', 'operator' => '=', 'value' => 1, 'boolean' => 'and'],
            ['type' => 'Basic', 'column' => 'b', 'operator' => '=', 'value' => 2, 'boolean' => 'or'],
        ]);

        $this->assertInstanceOf(OrNode::class, $node);
        $this->assertCount(2, $node->children);
        $this->assertInstanceOf(ComparisonNode::class, $node->children[0]);
        $this->assertInstanceOf(ComparisonNode::class, $node->children[1]);
    }

    #[Test]
    public function from_wheres_groups_and_tighter_than_or(): void
    {
        // a AND b OR c  =>  (a AND b) OR c
        $node = PredicateExtractor::fromWheres([
            ['type' => 'Basic', 'column' => 'a', 'operator' => '=', 'value' => 1, 'boolean' => 'and'],
            ['type' => 'Basic', 'column' => 'b', 'operator' => '=', 'value' => 2, 'boolean' => 'and'],
            ['type' => 'Basic', 'column' => 'c', 'operator' => '=', 'value' => 3, 'boolean' => 'or'],
        ]);

        $this->assertInstanceOf(OrNode::class, $node);
        $this->assertCount(2, $node->children);
        $this->assertInstanceOf(AndNode::class, $node->children[0]);
        $this->assertCount(2, $node->children[0]->children);
        $this->assertInstanceOf(ComparisonNode::class, $node->children[1]);
    }

    #[Test]
    public function from_wheres_returns_null_on_unsupported_clause(): void
    {
        $node = PredicateExtractor::fromWheres([
            ['type' => 'Basic', 'column' => 'a', 'operator' => '=', 'value' => 1, 'boolean' => 'and'],
            ['type' => 'Fulltext', 'columns' => ['b'], 'boolean' => 'or'],
        ]);

        $this->assertNull($node);
    }

    #[Test]
    public function from_wheres_empty_is_tautology(): void
    {
        $node = PredicateExtractor::fromWheres([]);

        $this->assertInstanceOf(AndNode::class, $node);
        $this->assertSame([], $node->children);
    }

    #[Test]
    public function from_wheres_bails_on_negated_boolean(): void
    {
        // whereNot()/orWhereNot() encode negation in the boolean; we cannot
        // represent it, so the whole tree must fall through to SQL.
        foreach (['and not', 'or not'] as $boolean) {
            $node = PredicateExtractor::fromWheres([
                ['type' => 'Basic', 'column' => 'a', 'operator' => '=', 'value' => 1, 'boolean' => 'and'],
                ['type' => 'Basic', 'column' => 'b', 'operator' => '=', 'value' => 2, 'boolean' => $boolean],
            ]);

            $this->assertNull($node, "boolean '{$boolean}' must bail");
        }
    }
}
