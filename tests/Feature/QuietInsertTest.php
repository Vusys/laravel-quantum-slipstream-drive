<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Testing\AssertsIdentityMapAgrees;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * A row inserted with model events muted (saveQuietly, withoutEvents — what
 * laravel-bitemporal's writer does) never fires the saved event the map
 * listens for, so the insert itself has to invalidate what it makes stale.
 */
final class QuietInsertTest extends TestCase
{
    use AssertsIdentityMapAgrees;

    #[Test]
    public function a_covered_query_sees_a_row_inserted_quietly(): void
    {
        User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'active' => true]);
        IdentityMap::flush();
        User::query()->where('active', true)->get();
        User::query()->where('active', true)->get();

        (new User(['name' => 'Bram', 'email' => 'bram@example.test', 'active' => true]))->saveQuietly();

        // No orderBy: an ordered pluck skips coverage, and coverage is what
        // this test needs to read through.
        $names = User::query()->where('active', true)->pluck('name')->all();
        sort($names);

        $this->assertSame(['Ada', 'Bram'], $names);
    }

    #[Test]
    public function an_empty_covered_query_sees_a_row_inserted_quietly(): void
    {
        User::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'active' => true]);
        IdentityMap::flush();
        $this->assertCount(0, User::query()->where('name', 'Bram')->get());
        $this->assertCount(0, User::query()->where('name', 'Bram')->get());

        User::withoutEvents(fn () => User::query()->create(['name' => 'Bram', 'email' => 'bram@example.test']));

        $this->assertCount(1, User::query()->where('name', 'Bram')->get());
    }

    #[Test]
    public function a_key_once_absent_is_found_after_a_quiet_insert(): void
    {
        $this->assertNull(User::query()->find(999));

        User::withoutEvents(fn () => User::query()->forceCreate(['id' => 999, 'name' => 'Cai', 'email' => 'cai@example.test']));

        $found = User::query()->whereKey(999)->first();

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame('Cai', $found->name);
    }

    #[Test]
    public function a_key_written_another_way_is_found_after_a_quiet_insert(): void
    {
        $this->assertNull(User::query()->find('0999'));

        User::withoutEvents(fn () => User::query()->forceCreate(['id' => 999, 'name' => 'Cai', 'email' => 'cai@example.test']));

        $found = User::query()->whereKey('0999')->first();
        $this->assertInstanceOf(User::class, $found);
    }

    #[Test]
    public function a_unique_value_once_absent_exists_after_a_quiet_insert(): void
    {
        $this->assertFalse(User::query()->where('email', 'dan@example.test')->exists());
        $this->assertFalse(User::query()->where('email', 'dan@example.test')->exists());

        User::withoutEvents(fn () => User::query()->create(['name' => 'Dan', 'email' => 'dan@example.test']));

        $this->assertTrue(User::query()->where('email', 'dan@example.test')->exists());
    }
}
