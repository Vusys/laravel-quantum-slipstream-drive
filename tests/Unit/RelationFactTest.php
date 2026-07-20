<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\QuantumSlipstreamDrive\Enums\RelationKind;
use Vusys\QuantumSlipstreamDrive\Knowledge\RelationFact;

final class RelationFactTest extends TestCase
{
    public function test_constructor_stores_all_fields(): void
    {
        $fact = new RelationFact(
            name: 'posts',
            kind: RelationKind::HasMany,
            loaded: true,
            complete: true,
            value: ['a', 'b'],
        );

        $this->assertSame('posts', $fact->name);
        $this->assertSame(RelationKind::HasMany, $fact->kind);
        $this->assertTrue($fact->loaded);
        $this->assertTrue($fact->complete);
        $this->assertSame(['a', 'b'], $fact->value);
    }

    public function test_unloaded_fact(): void
    {
        $fact = new RelationFact(
            name: 'user',
            kind: RelationKind::BelongsTo,
            loaded: false,
            complete: false,
            value: null,
        );

        $this->assertSame('user', $fact->name);
        $this->assertFalse($fact->loaded);
        $this->assertFalse($fact->complete);
        $this->assertNull($fact->value);
    }

    public function test_all_relation_kinds_have_backing_values(): void
    {
        $expected = [
            'belongsTo', 'hasOne', 'hasMany',
            'belongsToMany', 'morphTo', 'morphOne', 'morphMany',
        ];

        $actual = array_map(fn (RelationKind $k) => $k->value, RelationKind::cases());

        $this->assertSame($expected, $actual);
    }

    public function test_is_readonly(): void
    {
        $fact = new RelationFact('posts', RelationKind::HasMany, true, true, null);

        $reflection = new \ReflectionClass($fact);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_null_value_is_allowed(): void
    {
        $fact = new RelationFact('address', RelationKind::HasOne, true, true, null);

        $this->assertNull($fact->value);
    }

    public function test_model_instance_as_value(): void
    {
        $fact = new RelationFact('user', RelationKind::BelongsTo, true, true, new \stdClass);

        $this->assertInstanceOf(\stdClass::class, $fact->value);
    }
}
