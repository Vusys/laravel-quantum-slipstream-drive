<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Enums\EvaluationResult;
use Vusys\QuantumSlipstreamDrive\Enums\FactConfidence;
use Vusys\QuantumSlipstreamDrive\Enums\FactSource;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeFact;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeKnowledge;
use Vusys\QuantumSlipstreamDrive\Predicate\AndNode;
use Vusys\QuantumSlipstreamDrive\Predicate\ComparisonNode;
use Vusys\QuantumSlipstreamDrive\Predicate\OrNode;
use Vusys\QuantumSlipstreamDrive\Predicate\PredicateEvaluator;

final class PredicateEvaluatorOrTest extends TestCase
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

    #[Test]
    public function matches_when_first_branch_matches(): void
    {
        $attrs = $this->attributes(['status' => 'active', 'role' => 'user']);
        $node = new OrNode([
            new ComparisonNode('status', '=', 'active'),
            new ComparisonNode('role', '=', 'admin'),
        ]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function matches_when_second_branch_matches(): void
    {
        $attrs = $this->attributes(['status' => 'disabled', 'role' => 'admin']);
        $node = new OrNode([
            new ComparisonNode('status', '=', 'active'),
            new ComparisonNode('role', '=', 'admin'),
        ]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function rejects_when_every_branch_rejects(): void
    {
        $attrs = $this->attributes(['status' => 'disabled', 'role' => 'user']);
        $node = new OrNode([
            new ComparisonNode('status', '=', 'active'),
            new ComparisonNode('role', '=', 'admin'),
        ]);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function a_matching_branch_wins_over_an_unknown_branch(): void
    {
        // 'missing' has no fact -> Unknown; 'status' matches -> whole OR matches.
        $attrs = $this->attributes(['status' => 'active']);
        $node = new OrNode([
            new ComparisonNode('missing', '=', 'x'),
            new ComparisonNode('status', '=', 'active'),
        ]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function unknown_propagates_when_no_branch_matches_but_one_is_unknown(): void
    {
        $attrs = $this->attributes(['status' => 'disabled']);
        $node = new OrNode([
            new ComparisonNode('missing', '=', 'x'),
            new ComparisonNode('status', '=', 'active'),
        ]);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function empty_or_is_a_contradiction_and_rejects(): void
    {
        $attrs = $this->attributes(['status' => 'active']);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, new OrNode([])));
    }

    #[Test]
    public function nested_and_within_or_requires_all_conjuncts(): void
    {
        // (status = active AND role = admin) OR (status = pending)
        $node = new OrNode([
            new AndNode([
                new ComparisonNode('status', '=', 'active'),
                new ComparisonNode('role', '=', 'admin'),
            ]),
            new ComparisonNode('status', '=', 'pending'),
        ]);

        $match = $this->attributes(['status' => 'active', 'role' => 'admin']);
        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($match, $node));

        // First conjunct group fails (role mismatch), second branch fails too.
        $reject = $this->attributes(['status' => 'active', 'role' => 'user']);
        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($reject, $node));

        // Second branch alone carries it.
        $viaSecond = $this->attributes(['status' => 'pending', 'role' => 'user']);
        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($viaSecond, $node));
    }
}
