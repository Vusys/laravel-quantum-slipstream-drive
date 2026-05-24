<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;

/**
 * @extends Factory<Post>
 */
final class PostFactory extends Factory
{
    protected $model = Post::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tag_id' => null,
            'title' => fake()->sentence(4),
            'published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(['published' => true]);
    }
}
