<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Testing\AssertsIdentityMapAgrees;
use Vusys\QuantumSlipstreamDrive\Tests\Models\AmbientAwareUser;
use Vusys\QuantumSlipstreamDrive\Tests\Models\AmbientUser;
use Vusys\QuantumSlipstreamDrive\Tests\Models\Concerns\AmbientFilterBuilder;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class AssertsIdentityMapAgreesTest extends TestCase
{
    use AssertsIdentityMapAgrees;

    #[\Override]
    protected function tearDown(): void
    {
        AmbientFilterBuilder::$onlyActive = false;
        parent::tearDown();
    }

    #[Test]
    public function it_passes_when_the_map_and_the_database_agree(): void
    {
        $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'active' => true]);
        User::query()->create(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => false]);
        // Read back, not straight from create(): a created model lacks the
        // columns the database filled in (#148).
        IdentityMap::flush();

        $found = $this->assertIdentityMapAgrees(fn () => User::query()->find($ada->id));
        $this->assertIdentityMapAgrees(fn () => User::query()->where('active', true)->orderBy('id')->get());
        $this->assertIdentityMapAgrees(fn (): array => [User::query()->whereKey($ada->id)->first(), User::query()->count()]);

        $this->assertInstanceOf(User::class, $found);
    }

    #[Test]
    public function it_fails_when_the_map_skips_a_filter_applied_at_execution(): void
    {
        $id = AmbientUser::query()->create(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => false])->id;
        IdentityMap::flush();
        AmbientUser::query()->find($id);
        AmbientFilterBuilder::$onlyActive = true;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The identity map answered differently from the database.');

        $this->assertIdentityMapAgrees(fn () => AmbientUser::query()->find($id));
    }

    #[Test]
    public function a_builder_that_stands_the_map_aside_during_its_filter_agrees(): void
    {
        $id = AmbientAwareUser::query()->create(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => false])->id;
        IdentityMap::flush();
        AmbientAwareUser::query()->find($id);
        AmbientFilterBuilder::$onlyActive = true;

        $this->assertNull($this->assertIdentityMapAgrees(fn () => AmbientAwareUser::query()->find($id)));
    }
}
