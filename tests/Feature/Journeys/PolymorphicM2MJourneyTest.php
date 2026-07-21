<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Video;

final class PolymorphicM2MJourneyTest extends JourneyTestCase
{
    #[Test]
    public function polymorphic_m2m_stays_consistent_alongside_the_belongs_to_many_pivot(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'First', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'Second', 'published' => false]);

        $video = Video::create(['title' => 'Intro', 'published' => true]);

        $red = Tag::create(['name' => 'red', 'priority' => 1]);
        $green = Tag::create(['name' => 'green', 'priority' => 2]);

        $post->topics()->attach([$red->id]);
        $video->tags()->attach([$red->id, $green->id]);
        $post->tags()->attach([$green->id => ['active' => true, 'priority' => 3, 'role' => 'primary']]);

        $this->journey(PolymorphicM2MJourney::class)->shuffles(25)->run();
    }
}
