<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\AttributeFact;
use Vusys\QueryRicerExtreme\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;

final class AttributeKnowledgeTest extends TestCase
{
    public function test_satisfies_wildcard_requires_all_columns_known(): void
    {
        $knowledge = new AttributeKnowledge;
        $knowledge->allColumnsKnown = false;

        $this->assertFalse($knowledge->satisfies(['*']));

        $knowledge->allColumnsKnown = true;

        $this->assertTrue($knowledge->satisfies(['*']));
    }

    public function test_satisfies_specific_columns_checks_facts(): void
    {
        $knowledge = new AttributeKnowledge;
        $knowledge->set('id', $this->makeFact('id', 1));
        $knowledge->set('name', $this->makeFact('name', 'Alice'));

        $this->assertTrue($knowledge->satisfies(['id', 'name']));
        $this->assertFalse($knowledge->satisfies(['id', 'name', 'email']));
    }

    public function test_knows_column_returns_correctly(): void
    {
        $knowledge = new AttributeKnowledge;

        $this->assertFalse($knowledge->knows('id'));

        $knowledge->set('id', $this->makeFact('id', 1));

        $this->assertTrue($knowledge->knows('id'));
    }

    public function test_get_returns_fact_or_null(): void
    {
        $knowledge = new AttributeKnowledge;
        $fact = $this->makeFact('id', 1);
        $knowledge->set('id', $fact);

        $this->assertSame($fact, $knowledge->get('id'));
        $this->assertNull($knowledge->get('missing'));
    }

    public function test_satisfies_empty_columns_returns_true(): void
    {
        $knowledge = new AttributeKnowledge;

        $this->assertTrue($knowledge->satisfies([]));
    }

    private function makeFact(string $column, mixed $value): AttributeFact
    {
        return new AttributeFact(
            column: $column,
            originalValue: $value,
            currentValue: $value,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );
    }
}
