<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature\Journeys;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Post;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Tag;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;

final class RelationGraphJourneyTest extends JourneyTestCase
{
    #[Test]
    public function relation_reads_never_return_deleted_or_stale_children(): void
    {
        $tags = collect(['red', 'green', 'blue'])->map(
            fn (string $name): Tag => Tag::create(['name' => $name, 'priority' => 1]),
        );

        foreach (['Ada', 'Boole', 'Curie'] as $name) {
            $user = User::create([
                'name' => $name,
                'email' => strtolower($name).'@example.com',
                'active' => true,
            ]);

            foreach (range(1, 2) as $i) {
                $post = Post::create([
                    'user_id' => $user->id,
                    'title' => "{$name}-post-{$i}",
                    'published' => $i === 1,
                ]);
                $post->tags()->attach($tags->random()->id);
            }
        }

        $this->journey(RelationGraphJourney::class)->shuffles(30)->run();
    }
}
