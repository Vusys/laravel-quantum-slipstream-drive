<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Tests\Models\ComposedUser;
use Vusys\QuantumSlipstreamDrive\Tests\Models\ComposedUserBuilder;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\ThirdPartyBuilder;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A model whose builder belongs to another package still gets the identity
 * map, by composing InteractsWithIdentityMap onto that builder — and keeps
 * the other package's scopes and overrides.
 */
final class ComposedBuilderTest extends TestCase
{
    #[Test]
    public function the_composed_builder_is_both_builders(): void
    {
        $builder = ComposedUser::query();

        $this->assertInstanceOf(ComposedUserBuilder::class, $builder);
        $this->assertInstanceOf(ThirdPartyBuilder::class, $builder);
    }

    #[Test]
    public function a_repeat_lookup_is_served_from_memory(): void
    {
        $id = ComposedUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test'])->id;
        ComposedUser::query()->find($id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame('Ada', ComposedUser::query()->whereKey($id)->first()?->name);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function the_other_builders_scopes_and_overrides_still_apply(): void
    {
        ComposedUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'active' => true]);
        ComposedUser::query()->create(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => false]);
        ThirdPartyBuilder::$gets = 0;

        $names = ComposedUser::query()->active()->get()->pluck('name')->all();

        $this->assertSame(['Ada'], $names);
        $this->assertGreaterThan(0, ThirdPartyBuilder::$gets);
    }
}
