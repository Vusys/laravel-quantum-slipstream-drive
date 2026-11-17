<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Query\RawWriteInterceptor;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

/**
 * fresh() and refresh() ask for the database itself (through useWritePdo()), so
 * they always run SQL. A created model holds only what the insert set, and a
 * write the map can't see (a trigger, a cascade) only shows up in a real read.
 */
final class FreshReadTest extends TestCase
{
    #[Test]
    public function refresh_after_create_picks_up_database_defaults(): void
    {
        $alice = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->assertArrayNotHasKey('active', $alice->getAttributes());

        $alice->refresh();

        $this->assertTrue($alice->active);
    }

    #[Test]
    public function fresh_sees_a_write_the_map_did_not(): void
    {
        $alice = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::query()->find($alice->id);

        RawWriteInterceptorless::update($alice->id);

        $this->assertSame('Alicia', $alice->fresh()?->name);
    }

    #[Test]
    public function a_fresh_read_updates_the_cached_model(): void
    {
        $alice = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $alice->refresh();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->assertTrue(User::query()->find($alice->id)?->active);
        $this->assertSame(0, $count);
    }
}

/** A write the identity map doesn't observe, like a database trigger or cascade. */
final class RawWriteInterceptorless
{
    public static function update(int $id): void
    {
        RawWriteInterceptor::withoutInterception(
            fn (): int => DB::table('users')->where('id', $id)->update(['name' => 'Alicia']),
        );
    }
}
