<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Coverage\CoverageRegistry;
use Vusys\QuantumSlipstreamDrive\Enums\PlanType;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class RawBuilderInvalidationTest extends TestCase
{
    private IdentityMapStore $store;

    private CoverageRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->registry = resolve(CoverageRegistry::class);
        $this->store->flush();
        $this->registry->flush();
    }

    private function countSql(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }

    #[Test]
    public function raw_update_invalidates_a_cached_eloquent_read(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id); // warm the store

        // Prove it is cached: a second read issues no SQL.
        $warm = $this->countSql(function () use ($alice): void {
            $found = User::find($alice->id);
            $this->assertInstanceOf(User::class, $found);
            $this->assertTrue((bool) $found->active);
        });
        $this->assertSame(0, $warm, 'read should be served from memory before the raw write');

        // Raw builder write bypasses Eloquent entirely.
        DB::table('users')->where('id', $alice->id)->update(['active' => false]);

        // The cached row must not be served stale — a re-read reflects the write.
        $fresh = User::find($alice->id);
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertFalse((bool) $fresh->active, 'raw update must invalidate the cached read');
    }

    #[Test]
    public function raw_delete_invalidates_a_cached_eloquent_read(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id); // warm the store

        DB::table('users')->where('id', $alice->id)->delete();

        $this->assertNull(User::find($alice->id), 'raw delete must invalidate the cached read');
    }

    #[Test]
    public function raw_insert_invalidates_covered_collection(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::all(); // record complete coverage for the whole table

        DB::table('users')->insert(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);

        $names = User::all()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Alice', 'Bob'], $names, 'raw insert must invalidate covered collection completeness');
    }

    #[Test]
    public function raw_write_invalidation_is_observable(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id);

        $explanations = IdentityMap::explain(function () use ($alice): void {
            DB::table('users')->where('id', $alice->id)->update(['active' => false]);
        });

        $raw = array_values(array_filter(
            $explanations,
            static fn (Explanation $e): bool => $e->type === PlanType::RawWriteInvalidation,
        ));

        $this->assertNotEmpty($raw, 'a raw write must emit a RawWriteInvalidation explanation');
        $this->assertSame(User::class, $raw[0]->modelClass);
        $this->assertTrue($raw[0]->sqlExecuted);
    }

    #[Test]
    public function eloquent_writes_do_not_trigger_raw_invalidation(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id);

        // A modeled Eloquent update must be handled by the precise path, not the
        // conservative raw-write hook: no RawWriteInvalidation explanation.
        $explanations = IdentityMap::explain(function () use ($alice): void {
            $model = User::find($alice->id);
            $this->assertInstanceOf(User::class, $model);
            $model->update(['active' => false]);
        });

        $raw = array_filter(
            $explanations,
            static fn (Explanation $e): bool => $e->type === PlanType::RawWriteInvalidation,
        );

        $this->assertSame([], $raw, 'Eloquent writes must not be conservatively flushed by the raw-write hook');
    }
}
