<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class MonsterHordeJourneyTest extends JourneyTestCase
{
    #[Test]
    public function every_entity_stays_consistent_when_mutated_together(): void
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true, 'score' => 40]);
        $boole = User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false, 'score' => 60]);

        $first = Post::create(['user_id' => $ada->id, 'title' => 'First', 'published' => true]);
        Post::create(['user_id' => $boole->id, 'title' => 'Second', 'published' => false]);

        $red = Tag::create(['name' => 'red', 'priority' => 1]);
        Tag::create(['name' => 'green', 'priority' => 2]);

        $first->tags()->attach([$red->id => ['active' => true, 'priority' => 5, 'role' => 'primary']]);
        $ada->comments()->create(['body' => 'seed-a', 'likes' => 3]);
        $first->comments()->create(['body' => 'seed-c', 'likes' => 7]);

        $this->journey(MonsterHordeJourney::class)->shuffles(20)->run();
    }
}
