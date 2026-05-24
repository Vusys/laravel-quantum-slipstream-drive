<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Vusys\QueryRicerExtreme\Tests\Models\UuidUser;

/**
 * @extends Factory<UuidUser>
 */
final class UuidUserFactory extends Factory
{
    protected $model = UuidUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function softDeleted(): static
    {
        return $this->afterCreating(fn (UuidUser $user) => $user->delete());
    }
}
