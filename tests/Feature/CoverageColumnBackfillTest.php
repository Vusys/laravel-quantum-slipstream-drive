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

final class CoverageColumnBackfillTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();

        config(['quantum-slipstream-drive.partial_models' => 'backfill_missing_columns']);
    }

    /** @return list<User> */
    private function seedActiveUsers(): array
    {
        $users = [];

        foreach (['Alice' => 'alice', 'Bob' => 'bob', 'Carol' => 'carol'] as $name => $handle) {
            $users[] = User::create([
                'name' => $name,
                'email' => "$handle@example.com",
                'active' => true,
                'score' => strlen($name),
            ]);
        }

        $this->store->flush();

        return $users;
    }

    /** @return list<string> */
    private function captureSql(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $callback();

        return $queries;
    }

    #[Test]
    public function coverage_read_missing_a_column_issues_one_batched_keyed_select(): void
    {
        $this->seedActiveUsers();

        // Record coverage over a predicate, with a narrow column set.
        User::where('active', true)->get(['id', 'name', 'active']);

        $emails = null;
        $queries = $this->captureSql(function () use (&$emails): void {
            $emails = User::where('active', true)
                ->get(['id', 'email'])
                ->pluck('email')
                ->sort()
                ->values()
                ->all();
        });

        $this->assertCount(1, $queries, 'a coverage read missing one column must issue exactly one batched query, not a full re-query nor one-per-row');
        $sql = $queries[0];
        $this->assertStringContainsStringIgnoringCase(' in (', $sql, 'the batched backfill must be keyed by primary key IN (...)');
        $this->assertStringContainsStringIgnoringCase('email', $sql);
        $this->assertStringNotContainsStringIgnoringCase('active', $sql, 'the batched backfill selects missing columns by key, not by the coverage predicate');
        $this->assertSame(['alice@example.com', 'bob@example.com', 'carol@example.com'], $emails);
    }

    #[Test]
    public function batched_backfill_result_equals_a_bypassed_query(): void
    {
        $this->seedActiveUsers();

        User::where('active', true)->get(['id', 'name', 'active']);

        $slipstream = User::where('active', true)->get(['id', 'email'])->pluck('email')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn () => User::where('active', true)->get(['id', 'email'])->pluck('email')->sort()->values()->all()
        );

        $this->assertSame($oracle, $slipstream);
    }

    #[Test]
    public function repeat_read_after_batched_backfill_is_fully_memory_served(): void
    {
        $this->seedActiveUsers();

        User::where('active', true)->get(['id', 'name', 'active']);
        User::where('active', true)->get(['id', 'email']); // triggers batched backfill

        $emails = null;
        $queries = $this->captureSql(function () use (&$emails): void {
            $emails = User::where('active', true)->get(['id', 'email'])->pluck('email')->sort()->values()->all();
        });

        $this->assertSame([], $queries, 'once backfilled, the coverage read must be served entirely from memory');
        $this->assertSame(['alice@example.com', 'bob@example.com', 'carol@example.com'], $emails);
    }

    #[Test]
    public function batched_backfill_captures_its_explanation(): void
    {
        $this->seedActiveUsers();

        User::where('active', true)->get(['id', 'name', 'active']);

        $explanations = IdentityMap::explain(function (): void {
            User::where('active', true)->get(['id', 'email']);
        });

        $backfills = array_values(array_filter(
            $explanations,
            static fn (Explanation $e): bool => $e->type === PlanType::BackfillColumnsFromDatabase,
        ));

        $this->assertCount(1, $backfills, 'exactly one batched backfill explanation is expected');
        $this->assertSame('coverage-partial-column-batched-backfill', $backfills[0]->reason);
        $this->assertTrue($backfills[0]->sqlExecuted);
        $this->assertSame(['email'], $backfills[0]->missingKeys);
    }

    #[Test]
    public function batched_backfill_preserves_dirty_attributes(): void
    {
        $users = $this->seedActiveUsers();

        $collection = User::where('active', true)->get(['id', 'name', 'active']);

        // Dirty one cached row before the backfill fires.
        $alice = $collection->firstWhere('name', 'Alice');
        $this->assertInstanceOf(User::class, $alice);
        $alice->name = 'Locally Changed';

        User::where('active', true)->get(['id', 'email']); // batched backfill on email

        $this->assertSame('Locally Changed', $alice->name, 'a batched backfill must not clobber a dirty in-memory attribute');
        $this->assertTrue($alice->isDirty('name'));
        $this->assertSame($users[0]->email, $alice->email);
        $this->assertFalse($alice->isDirty('email'));
    }

    #[Test]
    public function batched_backfill_falls_through_when_a_covered_row_disappears(): void
    {
        $users = $this->seedActiveUsers();

        User::where('active', true)->get(['id', 'name', 'active']);

        // Delete one covered row out-of-band, bypassing identity-map invalidation.
        DB::table('users')->where('id', $users[1]->id)->delete();

        $slipstream = User::where('active', true)->get(['id', 'email'])->pluck('email')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn () => User::where('active', true)->get(['id', 'email'])->pluck('email')->sort()->values()->all()
        );

        $this->assertSame($oracle, $slipstream, 'when a covered row vanishes the read must fall through to SQL and match ground truth');
        $this->assertSame(['alice@example.com', 'carol@example.com'], $slipstream);
    }

    #[Test]
    public function default_mode_does_not_batch_backfill_the_coverage_path(): void
    {
        config(['quantum-slipstream-drive.partial_models' => 'query_normally']);
        $this->seedActiveUsers();

        User::where('active', true)->get(['id', 'name', 'active']);

        $emails = null;
        $queries = $this->captureSql(function () use (&$emails): void {
            $emails = User::where('active', true)->get(['id', 'email'])->pluck('email')->sort()->values()->all();
        });

        $this->assertCount(1, $queries, 'with backfill off, a missing-column coverage read falls through to a single full query');
        $this->assertStringNotContainsStringIgnoringCase(' in (', $queries[0], 'the fall-through re-queries by predicate, not a keyed backfill');
        $this->assertSame(['alice@example.com', 'bob@example.com', 'carol@example.com'], $emails);
    }
}
