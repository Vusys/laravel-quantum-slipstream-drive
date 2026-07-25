<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QuantumSlipstreamDrive\Enums\PlanType;
use Vusys\QuantumSlipstreamDrive\Explanation;
use Vusys\QuantumSlipstreamDrive\IdentityMap;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class RawReadServingTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('quantum-slipstream-drive.raw_reads.enabled', true);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
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

    /**
     * Ground truth for a raw read: run it with the identity map disabled so it
     * bypasses every cache and returns exactly what SQL yields.
     *
     * @param  \Closure(): mixed  $callback
     */
    private function bypassed(\Closure $callback): mixed
    {
        return $this->store->disabled($callback);
    }

    #[Test]
    public function covered_single_key_raw_read_issues_zero_sql_and_matches_ground_truth(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => 42]);
        // Simulate a fresh request: the create()-populated entry is gone, so the
        // Eloquent read below issues a genuine SELECT and snapshots the native row.
        $this->store->flush();

        $ground = $this->bypassed(fn (): mixed => DB::table('users')->where('id', $alice->id)->first());

        // Warm the identity map through Eloquent — captures the native row snapshot.
        User::find($alice->id);

        $served = null;
        $sql = $this->countSql(function () use ($alice, &$served): void {
            $served = DB::table('users')->where('id', $alice->id)->first();
        });

        $this->assertSame(0, $sql, 'a covered raw single-key read must issue zero SQL');
        $this->assertIsObject($served);
        $this->assertSame((array) $ground, (array) $served, 'served raw row must equal a bypassed query byte-for-byte');
    }

    #[Test]
    public function raw_find_shape_is_served(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $this->store->flush();

        $ground = $this->bypassed(fn (): mixed => DB::table('users')->find($alice->id));

        User::find($alice->id);

        $served = null;
        $sql = $this->countSql(function () use ($alice, &$served): void {
            $served = DB::table('users')->find($alice->id);
        });

        $this->assertSame(0, $sql);
        $this->assertIsObject($served);
        $this->assertSame((array) $ground, (array) $served);
    }

    #[Test]
    public function records_an_explanation_for_a_served_raw_read(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $this->store->flush();
        User::find($alice->id);

        $explanations = IdentityMap::explain(function () use ($alice): void {
            DB::table('users')->find($alice->id);
        });

        $plans = array_map(static fn (Explanation $e): PlanType => $e->type, $explanations);
        $this->assertContains(PlanType::ReturnRawRowFromMemory, $plans);
    }

    #[Test]
    public function uncovered_raw_read_falls_through_to_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        // No Eloquent read warmed the row: create() does not snapshot a native row.

        $served = null;
        $sql = $this->countSql(function () use ($alice, &$served): void {
            $served = DB::table('users')->find($alice->id);
        });

        $this->assertSame(1, $sql, 'an uncovered raw read must fall through to SQL');
        $this->assertIsObject($served);
    }

    #[Test]
    public function raw_read_with_extra_predicate_falls_through(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id);

        $sql = $this->countSql(function () use ($alice): void {
            DB::table('users')->where('id', $alice->id)->where('active', 1)->first();
        });

        $this->assertSame(1, $sql, 'a non-key predicate cannot be evaluated against the snapshot; must hit SQL');
    }

    #[Test]
    public function raw_read_with_column_projection_falls_through(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id);

        $sql = $this->countSql(function () use ($alice): void {
            DB::table('users')->select('name')->where('id', $alice->id)->first();
        });

        $this->assertSame(1, $sql, 'a projected read wants fewer columns than the snapshot; must hit SQL');
    }

    #[Test]
    public function covered_key_set_raw_read_is_served(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);

        $this->store->flush();

        $ground = $this->bypassed(fn (): array => DB::table('users')->whereIn('id', [$alice->id, $bob->id])->orderBy('id')->get()->all());

        User::findMany([$alice->id, $bob->id]);

        $served = null;
        $sql = $this->countSql(function () use ($alice, $bob, &$served): void {
            $served = DB::table('users')->whereIn('id', [$bob->id, $alice->id])->get()->all();
        });

        $this->assertSame(0, $sql, 'a fully covered key-set read must issue zero SQL');

        $this->assertIsArray($ground);
        $this->assertIsArray($served);
        $orderById = static function (array $rows): array {
            $rows = array_map(static fn (mixed $row): array => (array) $row, $rows);
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            return $rows;
        };

        $this->assertSame(
            $orderById($ground),
            $orderById($served),
            'served key-set (pk-ascending) must equal the bypassed rows',
        );
    }

    #[Test]
    public function covered_key_set_raw_read_with_non_primary_key_order_falls_through_and_matches_sql_order(): void
    {
        $zoe = User::create(['name' => 'Zoe', 'email' => 'zoe@example.com', 'active' => true]);
        $amy = User::create(['name' => 'Amy', 'email' => 'amy@example.com', 'active' => true]);
        $this->store->flush();

        $ground = $this->bypassed(fn (): array => DB::table('users')->whereIn('id', [$zoe->id, $amy->id])->orderBy('name')->get()->all());

        User::findMany([$zoe->id, $amy->id]);

        $served = null;
        $sql = $this->countSql(function () use ($zoe, $amy, &$served): void {
            $served = DB::table('users')->whereIn('id', [$zoe->id, $amy->id])->orderBy('name')->get()->all();
        });

        $this->assertSame(1, $sql, 'an explicit non-primary-key orderBy cannot be honoured from the snapshot; must hit SQL');

        $this->assertIsArray($ground);
        $this->assertIsArray($served);

        $names = array_map(static fn (mixed $row): mixed => ((array) $row)['name'], $served);
        $this->assertSame(['Amy', 'Zoe'], $names, 'served rows must follow the SQL orderBy(name), not pk-ascending');

        $toArrays = static fn (array $rows): array => array_map(static fn (mixed $row): array => (array) $row, $rows);
        $this->assertSame($toArrays($ground), $toArrays($served), 'result must match the bypassed SQL query exactly');
    }

    #[Test]
    public function partially_covered_key_set_raw_read_falls_through(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        $this->store->flush();

        // Only warm Alice — Bob has no native-row snapshot.
        User::find($alice->id);

        $sql = $this->countSql(function () use ($alice, $bob): void {
            DB::table('users')->whereIn('id', [$alice->id, $bob->id])->get();
        });

        $this->assertSame(1, $sql, 'one uncovered key forces the whole key-set read to SQL');
    }

    #[Test]
    public function eloquent_update_invalidates_the_raw_snapshot(): void
    {
        $created = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $id = $created->id;
        $this->store->flush();
        $alice = User::find($id);
        $this->assertInstanceOf(User::class, $alice);

        // Prove it is served first.
        $warm = $this->countSql(function () use ($id): void {
            DB::table('users')->find($id);
        });
        $this->assertSame(0, $warm);

        $alice->update(['name' => 'Alicia']);

        $served = null;
        $sql = $this->countSql(function () use ($id, &$served): void {
            $served = DB::table('users')->find($id);
        });

        $this->assertSame(1, $sql, 'a mutation must invalidate the snapshot so the raw read is not stale');
        $this->assertIsObject($served);
        $this->assertSame('Alicia', ((array) $served)['name']);
    }

    #[Test]
    public function raw_update_invalidates_the_raw_snapshot(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $this->store->flush();
        User::find($alice->id);

        // Prove the snapshot serves before the write.
        $warm = $this->countSql(function () use ($alice): void {
            DB::table('users')->find($alice->id);
        });
        $this->assertSame(0, $warm);

        DB::table('users')->where('id', $alice->id)->update(['name' => 'Alicia']);

        $served = null;
        $sql = $this->countSql(function () use ($alice, &$served): void {
            $served = DB::table('users')->find($alice->id);
        });

        $this->assertSame(1, $sql, 'a raw write flushes the class, dropping the snapshot');
        $this->assertIsObject($served);
        $this->assertSame('Alicia', ((array) $served)['name']);
    }

    #[Test]
    public function eloquent_reads_are_not_served_by_the_raw_builder(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id);
        $this->store->flush();

        // A cold Eloquent read must still run its own SQL (and not be short-circuited
        // by the raw-serving builder), then be served from the Eloquent path.
        $first = $this->countSql(function () use ($alice): void {
            User::find($alice->id);
        });
        $this->assertSame(1, $first, 'cold Eloquent read runs SQL');

        $second = $this->countSql(function () use ($alice): void {
            User::find($alice->id);
        });
        $this->assertSame(0, $second, 'warm Eloquent read served from the identity map');
    }
}
