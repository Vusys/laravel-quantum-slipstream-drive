<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Knowledge\RelationFact;
use Vusys\QueryRicerExtreme\Knowledge\RelationKnowledge;

final class RelationKnowledgeTest extends TestCase
{
    public function test_starts_empty(): void
    {
        $knowledge = new RelationKnowledge;

        $this->assertSame([], $knowledge->relations);
    }

    public function test_get_returns_null_for_unknown_relation(): void
    {
        $this->assertNull((new RelationKnowledge)->get('posts'));
    }

    public function test_set_and_get_round_trip(): void
    {
        $knowledge = new RelationKnowledge;
        $fact = new RelationFact('posts', RelationKind::HasMany, true, true, null);
        $knowledge->set('posts', $fact);

        $this->assertSame($fact, $knowledge->get('posts'));
    }

    public function test_is_loaded_false_when_not_set(): void
    {
        $this->assertFalse((new RelationKnowledge)->isLoaded('posts'));
    }

    public function test_is_loaded_false_when_loaded_flag_is_false(): void
    {
        $knowledge = new RelationKnowledge;
        $knowledge->set('posts', new RelationFact('posts', RelationKind::HasMany, false, false, null));

        $this->assertFalse($knowledge->isLoaded('posts'));
    }

    public function test_is_loaded_true_when_loaded_flag_is_true(): void
    {
        $knowledge = new RelationKnowledge;
        $knowledge->set('posts', new RelationFact('posts', RelationKind::HasMany, true, false, null));

        $this->assertTrue($knowledge->isLoaded('posts'));
    }

    public function test_multiple_relations_stored_independently(): void
    {
        $knowledge = new RelationKnowledge;
        $posts = new RelationFact('posts', RelationKind::HasMany, true, true, null);
        $user = new RelationFact('user', RelationKind::BelongsTo, true, true, null);

        $knowledge->set('posts', $posts);
        $knowledge->set('user', $user);

        $this->assertSame($posts, $knowledge->get('posts'));
        $this->assertSame($user, $knowledge->get('user'));
    }

    public function test_overwrite_relation(): void
    {
        $knowledge = new RelationKnowledge;
        $first = new RelationFact('posts', RelationKind::HasMany, false, false, null);
        $second = new RelationFact('posts', RelationKind::HasMany, true, true, null);

        $knowledge->set('posts', $first);
        $knowledge->set('posts', $second);

        $this->assertSame($second, $knowledge->get('posts'));
    }
}
