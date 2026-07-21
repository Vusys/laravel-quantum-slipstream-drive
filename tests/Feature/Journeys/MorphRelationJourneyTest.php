<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Comment;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class MorphRelationJourneyTest extends JourneyTestCase
{
    #[Test]
    public function morph_relations_stay_consistent_through_reparenting_and_deletes(): void
    {
        $this->seedGraph();

        $this->journey(MorphRelationJourney::class)->shuffles(20)->run();
    }

    #[Test]
    public function morph_relations_stay_consistent_under_an_active_morph_map(): void
    {
        Relation::morphMap(['user' => User::class, 'post' => Post::class], false);

        try {
            $this->seedGraph();

            $this->journey(MorphRelationJourney::class)->shuffles(20)->run();
        } finally {
            Relation::morphMap([], false);
            Relation::requireMorphMap(false);
        }
    }

    private function seedGraph(): void
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        $boole = User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);

        $first = Post::create(['user_id' => $ada->id, 'title' => 'First', 'published' => true]);
        $second = Post::create(['user_id' => $boole->id, 'title' => 'Second', 'published' => false]);

        $ada->comments()->create(['body' => 'seed-a', 'likes' => 3]);
        $boole->comments()->create(['body' => 'seed-b', 'likes' => 1]);
        $first->comments()->create(['body' => 'seed-c', 'likes' => 7]);
        Comment::create([
            'commentable_type' => $second->getMorphClass(),
            'commentable_id' => $second->id,
            'body' => 'seed-d',
            'likes' => 0,
        ]);
    }
}
