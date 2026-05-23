<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\DataProviders;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

#[Group('comprehensive')]
final class WhereShapeTest extends TestCase
{
    /** @return array<string, array{string, mixed, mixed, EvaluationResult}> */
    public static function predicateEvaluationProvider(): array
    {
        return [
            'eq match' => ['=', 'alice@example.com', 'alice@example.com', EvaluationResult::Match],
            'eq reject' => ['=', 'alice@example.com', 'bob@example.com', EvaluationResult::Reject],
            'neq match' => ['!=', 'alice@example.com', 'bob@example.com', EvaluationResult::Match],
            'neq reject' => ['!=', 'alice@example.com', 'alice@example.com', EvaluationResult::Reject],
            'diamond neq match' => ['<>', 'alice@example.com', 'bob@example.com', EvaluationResult::Match],
            'diamond neq reject' => ['<>', 'alice@example.com', 'alice@example.com', EvaluationResult::Reject],
            'int eq match' => ['=', 1, 1, EvaluationResult::Match],
            'int eq reject' => ['=', 1, 2, EvaluationResult::Reject],
            'bool eq match' => ['=', 1, true, EvaluationResult::Match],
            'null eq match' => ['=', null, null, EvaluationResult::Match],
            'null eq reject' => ['=', 'x', null, EvaluationResult::Reject],
        ];
    }

    #[DataProvider('predicateEvaluationProvider')]
    public function test_predicate_evaluator_against_known_attribute(
        string $operator,
        mixed $storedValue,
        mixed $predicateValue,
        EvaluationResult $expected,
    ): void {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'email',
            'operator' => $operator,
            'value' => $predicateValue,
            'boolean' => 'and',
        ]);

        $this->assertNotNull($node);

        $attributes = new AttributeKnowledge;
        $attributes->facts['email'] = new AttributeFact(
            column: 'email',
            originalValue: $storedValue,
            currentValue: $storedValue,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );

        $evaluator = new PredicateEvaluator;
        $this->assertSame($expected, $evaluator->evaluate($attributes, $node));
    }

    /** @return array<string, array{string, mixed, int}> */
    public static function liveQueryProvider(): array
    {
        return [
            'active=true returns cached model without SQL' => ['active', true, 0],
            'active=false returns empty without SQL' => ['active', false, 0],
        ];
    }

    #[DataProvider('liveQueryProvider')]
    public function test_where_shape_served_from_cache(string $column, mixed $value, int $expectedQueries): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com', 'active' => true]);
        resolve(IdentityMapStore::class)->flush();

        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::whereKey([$user->id])->where($column, $value)->get();

        $this->assertSame($expectedQueries, $queries);
    }

    public function test_null_predicate_on_deleted_at_column(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        resolve(IdentityMapStore::class)->flush();

        User::find($user->id);

        $node = PredicateExtractor::fromWhere([
            'type' => 'Null',
            'column' => 'deleted_at',
            'boolean' => 'and',
        ]);

        $this->assertNotNull($node);

        $attributes = new AttributeKnowledge;
        $attributes->facts['deleted_at'] = new AttributeFact(
            column: 'deleted_at',
            originalValue: null,
            currentValue: null,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );

        $evaluator = new PredicateEvaluator;
        $this->assertSame(EvaluationResult::Match, $evaluator->evaluate($attributes, $node));
    }

    /** @return array<string, array{list<mixed>, bool, EvaluationResult}> */
    public static function inNodeProvider(): array
    {
        return [
            'in — match' => [['alice@example.com', 'bob@example.com'], false, EvaluationResult::Match],
            'in — reject' => [['bob@example.com', 'carol@example.com'], false, EvaluationResult::Reject],
            'not in — match' => [['bob@example.com', 'carol@example.com'], true, EvaluationResult::Match],
            'not in — reject' => [['alice@example.com', 'bob@example.com'], true, EvaluationResult::Reject],
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    #[DataProvider('inNodeProvider')]
    public function test_in_predicate_evaluation(array $values, bool $negated, EvaluationResult $expected): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => $negated ? 'NotIn' : 'In',
            'column' => 'email',
            'values' => $values,
            'boolean' => 'and',
        ]);

        $this->assertNotNull($node);

        $attributes = new AttributeKnowledge;
        $attributes->facts['email'] = new AttributeFact(
            column: 'email',
            originalValue: 'alice@example.com',
            currentValue: 'alice@example.com',
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );

        $evaluator = new PredicateEvaluator;
        $this->assertSame($expected, $evaluator->evaluate($attributes, $node));
    }
}
