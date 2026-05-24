<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Predicate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\BetweenNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;

final class PredicateEvaluatorRangeTest extends TestCase
{
    private PredicateEvaluator $evaluator;

    #[\Override]
    protected function setUp(): void
    {
        $this->evaluator = new PredicateEvaluator;
    }

    /** @param array<string, mixed> $values */
    private function attributes(array $values): AttributeKnowledge
    {
        $knowledge = new AttributeKnowledge;

        foreach ($values as $column => $value) {
            $knowledge->set($column, new AttributeFact(
                column: $column,
                originalValue: $value,
                currentValue: $value,
                isDirty: false,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            ));
        }

        return $knowledge;
    }

    /** @param array<string, array{original: mixed, current: mixed}> $values */
    private function dirtyAttributes(array $values): AttributeKnowledge
    {
        $knowledge = new AttributeKnowledge;

        foreach ($values as $column => ['original' => $original, 'current' => $current]) {
            $knowledge->set($column, new AttributeFact(
                column: $column,
                originalValue: $original,
                currentValue: $current,
                isDirty: true,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            ));
        }

        return $knowledge;
    }

    // ---------------------------------------------------------------------
    // Comparison: >, >=, <, <=
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{int|float, string, int|float, EvaluationResult}> */
    public static function numericComparisonCases(): iterable
    {
        yield 'gt match int' => [25, '>', 18, EvaluationResult::Match];
        yield 'gt reject int equal' => [18, '>', 18, EvaluationResult::Reject];
        yield 'gt reject int less' => [10, '>', 18, EvaluationResult::Reject];
        yield 'gte match equal' => [18, '>=', 18, EvaluationResult::Match];
        yield 'gte match greater' => [25, '>=', 18, EvaluationResult::Match];
        yield 'gte reject less' => [10, '>=', 18, EvaluationResult::Reject];
        yield 'lt match' => [10, '<', 18, EvaluationResult::Match];
        yield 'lt reject equal' => [18, '<', 18, EvaluationResult::Reject];
        yield 'lt reject greater' => [25, '<', 18, EvaluationResult::Reject];
        yield 'lte match equal' => [18, '<=', 18, EvaluationResult::Match];
        yield 'lte match less' => [10, '<=', 18, EvaluationResult::Match];
        yield 'lte reject greater' => [25, '<=', 18, EvaluationResult::Reject];
        yield 'gt match float' => [3.5, '>', 2.5, EvaluationResult::Match];
        yield 'lt reject mixed int/float' => [4, '<', 3.5, EvaluationResult::Reject];
        yield 'gte match int vs float' => [3, '>=', 2.5, EvaluationResult::Match];
    }

    #[Test]
    #[DataProvider('numericComparisonCases')]
    public function numeric_comparison_resolves(int|float $attrValue, string $op, int|float $predicateValue, EvaluationResult $expected): void
    {
        $attrs = $this->attributes(['n' => $attrValue]);
        $node = new ComparisonNode('n', $op, $predicateValue);

        $this->assertSame($expected, $this->evaluator->evaluate($attrs, $node));
    }

    /** @return iterable<string, array{string}> */
    public static function rangeOperators(): iterable
    {
        yield '>' => ['>'];
        yield '>=' => ['>='];
        yield '<' => ['<'];
        yield '<=' => ['<='];
    }

    #[Test]
    #[DataProvider('rangeOperators')]
    public function range_with_string_attribute_returns_unknown(string $op): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);
        $node = new ComparisonNode('name', $op, 'Bob');

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    #[DataProvider('rangeOperators')]
    public function range_with_numeric_string_returns_unknown(string $op): void
    {
        // Numeric strings still bypass — driver semantics not modelled.
        $attrs = $this->attributes(['n' => '25']);
        $node = new ComparisonNode('n', $op, 18);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    #[DataProvider('rangeOperators')]
    public function range_with_null_attribute_returns_unknown(string $op): void
    {
        $attrs = $this->attributes(['n' => null]);
        $node = new ComparisonNode('n', $op, 18);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    #[DataProvider('rangeOperators')]
    public function range_with_missing_column_returns_unknown(string $op): void
    {
        $attrs = $this->attributes(['other' => 5]);
        $node = new ComparisonNode('n', $op, 18);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    #[DataProvider('rangeOperators')]
    public function range_with_numeric_attribute_and_string_predicate_returns_unknown(string $op): void
    {
        $attrs = $this->attributes(['n' => 25]);
        $node = new ComparisonNode('n', $op, 'eighteen');

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    #[DataProvider('rangeOperators')]
    public function range_with_numeric_attribute_and_numeric_string_predicate_returns_unknown(string $op): void
    {
        $attrs = $this->attributes(['n' => 25]);
        $node = new ComparisonNode('n', $op, '18');

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    // ---------------------------------------------------------------------
    // BETWEEN
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{int|float, int|float, int|float, bool, EvaluationResult}> */
    public static function betweenCases(): iterable
    {
        // value, min, max, negated, expected
        yield 'between match middle' => [20, 10, 30, false, EvaluationResult::Match];
        yield 'between match lower boundary' => [10, 10, 30, false, EvaluationResult::Match];
        yield 'between match upper boundary' => [30, 10, 30, false, EvaluationResult::Match];
        yield 'between reject below' => [5, 10, 30, false, EvaluationResult::Reject];
        yield 'between reject above' => [40, 10, 30, false, EvaluationResult::Reject];
        yield 'between match float' => [2.5, 1.0, 3.0, false, EvaluationResult::Match];

        yield 'not between match below' => [5, 10, 30, true, EvaluationResult::Match];
        yield 'not between match above' => [40, 10, 30, true, EvaluationResult::Match];
        yield 'not between reject middle' => [20, 10, 30, true, EvaluationResult::Reject];
        yield 'not between reject lower' => [10, 10, 30, true, EvaluationResult::Reject];
        yield 'not between reject upper' => [30, 10, 30, true, EvaluationResult::Reject];
    }

    #[Test]
    #[DataProvider('betweenCases')]
    public function between_resolves(int|float $value, int|float $min, int|float $max, bool $negated, EvaluationResult $expected): void
    {
        $attrs = $this->attributes(['n' => $value]);
        $node = new BetweenNode('n', $min, $max, $negated);

        $this->assertSame($expected, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_string_attribute_returns_unknown(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);
        $node = new BetweenNode('name', 'A', 'Z', false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_numeric_string_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => '20']);
        $node = new BetweenNode('n', 10, 30, false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_null_attribute_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => null]);
        $node = new BetweenNode('n', 10, 30, false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_missing_column_returns_unknown(): void
    {
        $attrs = $this->attributes(['other' => 5]);
        $node = new BetweenNode('n', 10, 30, false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_between_with_null_attribute_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => null]);
        $node = new BetweenNode('n', 10, 30, true);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_non_numeric_min_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => 20]);
        $node = new BetweenNode('n', 'low', 30, false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_non_numeric_max_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => 20]);
        $node = new BetweenNode('n', 10, 'high', false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_numeric_string_min_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => 20]);
        $node = new BetweenNode('n', '10', 30, false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_with_numeric_string_max_returns_unknown(): void
    {
        $attrs = $this->attributes(['n' => 20]);
        $node = new BetweenNode('n', 10, '30', false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    // ---------------------------------------------------------------------
    // Process-truth routing
    // ---------------------------------------------------------------------

    #[Test]
    public function range_default_uses_original_value(): void
    {
        // original=10 (rejects > 18), current=30 (matches > 18)
        $attrs = $this->dirtyAttributes(['n' => ['original' => 10, 'current' => 30]]);
        $node = new ComparisonNode('n', '>', 18);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function range_process_truth_true_uses_current_value(): void
    {
        $attrs = $this->dirtyAttributes(['n' => ['original' => 10, 'current' => 30]]);
        $node = new ComparisonNode('n', '>', 18);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node, processTruth: true));
    }

    #[Test]
    public function between_default_uses_original_value(): void
    {
        // original=20 (in [10,30]), current=40 (outside)
        $attrs = $this->dirtyAttributes(['n' => ['original' => 20, 'current' => 40]]);
        $node = new BetweenNode('n', 10, 30, false);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function between_process_truth_true_uses_current_value(): void
    {
        $attrs = $this->dirtyAttributes(['n' => ['original' => 20, 'current' => 40]]);
        $node = new BetweenNode('n', 10, 30, false);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node, processTruth: true));
    }

    // ---------------------------------------------------------------------
    // AndNode mixed with range/between
    // ---------------------------------------------------------------------

    #[Test]
    public function and_short_circuits_on_range_reject(): void
    {
        $attrs = $this->attributes(['n' => 5, 'status' => 'active']);
        $node = new AndNode([
            new ComparisonNode('n', '>', 18),                  // Reject
            new ComparisonNode('status', '=', 'active'),       // Match (would have been)
        ]);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_with_between_reject_returns_reject(): void
    {
        $attrs = $this->attributes(['n' => 40, 'status' => 'active']);
        $node = new AndNode([
            new ComparisonNode('status', '=', 'active'),       // Match
            new BetweenNode('n', 10, 30, false),               // Reject
        ]);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_with_range_match_and_equality_match_returns_match(): void
    {
        $attrs = $this->attributes(['n' => 25, 'status' => 'active']);
        $node = new AndNode([
            new ComparisonNode('n', '>=', 18),
            new ComparisonNode('status', '=', 'active'),
        ]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_with_range_unknown_returns_unknown(): void
    {
        // String attribute → range returns Unknown; equality still matches.
        $attrs = $this->attributes(['n' => 'abc', 'status' => 'active']);
        $node = new AndNode([
            new ComparisonNode('n', '>', 18),                  // Unknown
            new ComparisonNode('status', '=', 'active'),       // Match
        ]);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }
}
