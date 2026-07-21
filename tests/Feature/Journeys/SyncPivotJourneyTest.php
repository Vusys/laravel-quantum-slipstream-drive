<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class SyncPivotJourneyTest extends JourneyTestCase
{
    #[Test]
    public function pivot_coverage_tracks_sync_detach_and_pivot_attribute_edits(): void
    {
        $this->seedPivots();

        $this->journey(SyncPivotJourney::class)->shuffles(30)->run();
    }

    private function seedPivots(): void
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        $boole = User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);

        $first = Post::create(['user_id' => $ada->id, 'title' => 'First', 'published' => true]);
        $second = Post::create(['user_id' => $boole->id, 'title' => 'Second', 'published' => false]);
        Post::create(['user_id' => $ada->id, 'title' => 'Third', 'published' => true]);

        $red = Tag::create(['name' => 'red', 'priority' => 1]);
        $green = Tag::create(['name' => 'green', 'priority' => 2]);
        $blue = Tag::create(['name' => 'blue', 'priority' => 3]);

        $first->tags()->attach([$red->id => ['active' => true, 'priority' => 5, 'role' => 'primary']]);
        $first->tags()->attach([$green->id => ['active' => false, 'priority' => 2, 'role' => 'secondary']]);
        $second->tags()->attach([$blue->id => ['active' => true, 'priority' => 1, 'role' => 'primary']]);
    }
}
