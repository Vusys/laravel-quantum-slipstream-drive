<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit\Predicate;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Driver\SqliteSemantics;
use Vusys\QuantumSlipstreamDrive\Enums\EvaluationResult;
use Vusys\QuantumSlipstreamDrive\Enums\FactConfidence;
use Vusys\QuantumSlipstreamDrive\Enums\FactSource;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeFact;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeKnowledge;
use Vusys\QuantumSlipstreamDrive\Predicate\ComparisonNode;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateEvaluator;

final class PredicateEvaluatorJsonPathTest extends TestCase
{
    private PredicateEvaluator $evaluator;

    #[\Override]
    protected function setUp(): void
    {
        // SQLite uses byte-deterministic string equality, matching json_extract.
        $this->evaluator = new PredicateEvaluator(new SqliteSemantics);
    }

    private function payload(string $json): AttributeKnowledge
    {
        $knowledge = new AttributeKnowledge;
        $knowledge->set('payload', new AttributeFact(
            column: 'payload',
            originalValue: $json,
            currentValue: $json,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        ));

        return $knowledge;
    }

    #[Test]
    public function string_leaf_equality_matches(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"plan":"pro"}'),
            new ComparisonNode('payload->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Match, $result);
    }

    #[Test]
    public function string_leaf_equality_rejects_a_different_value(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"plan":"free"}'),
            new ComparisonNode('payload->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Reject, $result);
    }

    #[Test]
    public function string_leaf_inequality_is_the_inverse(): void
    {
        $match = $this->evaluator->evaluate(
            $this->payload('{"plan":"free"}'),
            new ComparisonNode('payload->plan', '!=', 'pro'),
        );
        $reject = $this->evaluator->evaluate(
            $this->payload('{"plan":"pro"}'),
            new ComparisonNode('payload->plan', '<>', 'pro'),
        );

        $this->assertSame(EvaluationResult::Match, $match);
        $this->assertSame(EvaluationResult::Reject, $reject);
    }

    #[Test]
    public function nested_path_navigates_object_keys(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"billing":{"plan":"pro"}}'),
            new ComparisonNode('payload->billing->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Match, $result);
    }

    #[Test]
    public function missing_path_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"plan":"pro"}'),
            new ComparisonNode('payload->tier', '=', 'gold'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result, 'a missing JSON path must defer to SQL, not decide');
    }

    #[Test]
    public function json_null_leaf_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"plan":null}'),
            new ComparisonNode('payload->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function numeric_leaf_defers_to_sql(): void
    {
        // JSON number vs bound value coerces differently per driver — defer.
        $result = $this->evaluator->evaluate(
            $this->payload('{"age":25}'),
            new ComparisonNode('payload->age', '=', '25'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function boolean_leaf_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"active":true}'),
            new ComparisonNode('payload->active', '=', 'true'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function object_leaf_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"plan":{"name":"pro"}}'),
            new ComparisonNode('payload->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function range_operator_over_json_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"plan":"pro"}'),
            new ComparisonNode('payload->plan', '>', 'a'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function non_string_predicate_value_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"age":25}'),
            new ComparisonNode('payload->age', '=', 25),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function invalid_json_base_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('not json at all'),
            new ComparisonNode('payload->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function unknown_base_column_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            new AttributeKnowledge,
            new ComparisonNode('payload->plan', '=', 'pro'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }

    #[Test]
    public function array_index_segment_defers_to_sql(): void
    {
        $result = $this->evaluator->evaluate(
            $this->payload('{"tags":["a","b"]}'),
            new ComparisonNode('payload->tags[0]', '=', 'a'),
        );

        $this->assertSame(EvaluationResult::Unknown, $result);
    }
}
