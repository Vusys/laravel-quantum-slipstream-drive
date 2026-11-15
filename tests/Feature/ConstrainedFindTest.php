<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\ComposedUser;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * find() on a builder that already carries where clauses must honour them: a
 * cached model is only the answer if it also matches those clauses.
 */
final class ConstrainedFindTest extends TestCase
{
    #[Test]
    public function a_cached_model_that_fails_the_where_is_not_found(): void
    {
        $id = User::query()->create(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => false])->id;
        User::query()->find($id);

        $this->assertNull(User::query()->where('active', true)->find($id));
    }

    #[Test]
    public function a_cached_model_that_passes_the_where_is_found(): void
    {
        $id = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'active' => true])->id;
        User::query()->find($id);

        $found = User::query()->where('active', true)->find($id);

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame('Ada', $found->name);
    }

    #[Test]
    public function a_composed_builders_scope_is_honoured_by_find(): void
    {
        $id = ComposedUser::query()->create(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => false])->id;
        ComposedUser::query()->find($id);

        $this->assertNull(ComposedUser::query()->active()->find($id));
    }
}
