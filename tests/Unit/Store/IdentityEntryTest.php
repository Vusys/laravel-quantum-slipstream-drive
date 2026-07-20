<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit\Store;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Enums\FactConfidence;
use Vusys\QuantumSlipstreamDrive\Enums\FactSource;
use Vusys\QuantumSlipstreamDrive\Enums\LifecycleState;
use Vusys\QuantumSlipstreamDrive\Enums\RelationKind;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeFact;
use Vusys\QuantumSlipstreamDrive\Knowledge\AttributeKnowledge;
use Vusys\QuantumSlipstreamDrive\Knowledge\RelationFact;
use Vusys\QuantumSlipstreamDrive\Knowledge\RelationKnowledge;
use Vusys\QuantumSlipstreamDrive\Store\IdentityEntry;

final class IdentityEntryTest extends TestCase
{
    #[Test]
    public function clone_produces_independent_attribute_knowledge(): void
    {
        $entry = $this->makeEntry();
        $entry->attributes->set('name', $this->fact('name', 'original'));

        $clone = clone $entry;
        $clone->attributes->set('name', $this->fact('name', 'mutated-on-clone'));

        $originalFact = $entry->attributes->get('name');
        self::assertNotNull($originalFact);
        self::assertSame('original', $originalFact->currentValue);
    }

    #[Test]
    public function clone_produces_independent_relation_knowledge(): void
    {
        $entry = $this->makeEntry();

        $clone = clone $entry;
        $clone->relations->set('posts', new RelationFact(
            name: 'posts',
            kind: RelationKind::HasMany,
            loaded: true,
            complete: true,
            value: null,
        ));

        self::assertFalse(
            $entry->relations->isLoaded('posts'),
            '__clone must deep-copy RelationKnowledge; mutating the clone leaked into the original.',
        );
    }

    private function fact(string $column, mixed $value): AttributeFact
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

    private function makeEntry(): IdentityEntry
    {
        return new IdentityEntry(
            connection: 'default',
            modelClass: Model::class,
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
            model: new class extends Model {},
            attributes: new AttributeKnowledge,
            relations: new RelationKnowledge,
            state: LifecycleState::Exists,
            version: 1,
        );
    }
}
