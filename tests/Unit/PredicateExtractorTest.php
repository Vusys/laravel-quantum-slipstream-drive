<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Predicate\BetweenNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;

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
            'operator' => 'like',
            'value' => 'A%',
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
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
}
