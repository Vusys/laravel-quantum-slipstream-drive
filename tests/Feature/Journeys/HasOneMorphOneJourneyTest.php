<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Image;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Profile;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class HasOneMorphOneJourneyTest extends JourneyTestCase
{
    #[Test]
    public function has_one_and_morph_one_track_ground_truth_through_edits(): void
    {
        $ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);
        $boole = User::create(['name' => 'Boole', 'email' => 'boole@example.com', 'active' => false]);

        Profile::create(['user_id' => $ada->id, 'headline' => 'first', 'public' => true]);

        $post = Post::create(['user_id' => $ada->id, 'title' => 'P', 'published' => true]);
        Post::create(['user_id' => $boole->id, 'title' => 'Q', 'published' => false]);

        Image::create(['imageable_type' => $post->getMorphClass(), 'imageable_id' => $post->id, 'url' => 'p.png', 'primary' => true]);

        $this->journey(HasOneMorphOneJourney::class)->shuffles(25)->run();
    }
}
